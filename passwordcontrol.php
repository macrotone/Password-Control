<?php
/**
 * @version        passwordcontrol.php 0.0.4
 * @package        Password Control
 * @copyright      (C) 2011 Macrotone Consulting Ltd - All rights reserved
 * @Website        http://www.macrotone.co.uk
 * @license        GNU Public License version 2 or above.  See LICENSE.txt
 *                 http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
 *
 */

// no direct access
defined('_JEXEC') or die('Restricted access');

jimport('joomla.event.plugin');
jimport('joomla.plugin.plugin');
jimport('joomla.application.component.helper');
jimport('joomla.html.parameter');

class plgSystemPasswordControl extends JPlugin
{
   function plgSystemPasswordControl(&$subject, $config)
   {
      parent::__construct($subject, $config);

      $app = JFactory::getApplication();
      $language =& JFactory::getLanguage();
      $db = JFactory::getDbo();
      $db->setQuery('SELECT count(name) FROM #__extensions WHERE name = "com_passwordcontrol"');
      $rowexists = $db->loadResult();
      if ( $rowexists == "1" )
      {
         $language->load('com_passwordcontrol');
      } else {
         $language->load('plg_system_passwordcontrol', JPATH_ADMINISTRATOR);
      }
   }

   function onContentPrepareForm($form, $data)
   {
      if (!($form instanceof JForm)) {
         $this->_subject->setError('JERROR_NOT_A_FORM');
         return false;
      }

      // Only display on supported forms, otherwise just return
      if (!($form->getName() == 'com_users.profile' && JRequest::getVar('layout') == 'edit')) {
         return true;
      }

      $app = & JFactory::getApplication();
      if ( $app->isAdmin() || JDEBUG ) { return; }

      $user = & JFactory::getUser();
      if ( $user->guest ) { return; }

      $session = JFactory::getSession();
      $passwordforce = (int) $session->get('password_force',0,'PasswordControl');
      $session->set('password_invalid', '0', 'PasswordControl');   // initialise or reset.

      // Make password specification mandatory
      if ($passwordforce) {
         $form->setFieldAttribute('password1', 'required', 'true');
         $form->setFieldAttribute('password2', 'required', 'true');
      } else {
         $form->setFieldAttribute('password1', 'required', 'false');
         $form->setFieldAttribute('password2', 'required', 'false');
      }
      return true;
   }

   function onAfterRoute()
   {
      $app = & JFactory::getApplication();
      if ( $app->isAdmin() || JDEBUG ) { return; }   // If admin or debugging return

      $user = & JFactory::getUser();
      if ( $user->guest ) { return; }  // Return if a guest

      // Set up defaults
      $session = JFactory::getSession();
      $option = JRequest::getCmd('option');
      $view   = JRequest::getVar('view');
      $task   = JRequest::getVar('task');
      $layout = JRequest::getVar('layout');

      $editProfileOption   = "com_users";
      $editProfileLayout   = "edit";
      $editProfileSaveTask = "profile.save";
      $editProfileView     = "profile";

      $userId = $user->id;

      $language =& JFactory::getLanguage();
      $vals = $this->whichparams();
      $params = $vals [0];
      if ( $vals[1] == 'component' ) {
         $language->load('com_passwordcontrol');
      } else {
         $language->load('plg_system_passwordcontrol', JPATH_ADMINISTRATOR);
      }
      $session->set('paramsset', 1, 'PasswordControl');   // Indicator for onUserAfterSave

      // forcefirst 0 = no, everything else means force password change on first logon
      if ( $params->get('forcefirst', '0') == 0) { $forcefirst = 0; }
      else { $forcefirst = 1; }

      $ndays = $params->get('ndays','0');
      if ( $ndays > 0 ) { $session->set('periodicity', $ndays, 'PasswordControl'); }

      $npwds = $params->get('npwds','1');
      $session->set('npasswords', $npwds, 'PasswordControl');

      $dbchks = $params->get('dbchecks','0');
      $session->set('dbchecks', $dbchks, 'PasswordControl');

      $dbver = $this->check_dbversion();
      if (strlen($dbver)) {
         $dbvals = array();
         $dbvals = $this->explodeX(array(".","-"),$dbver);
      }
      // Check if we need the binlog_format settings  1 says yes we do, 0 otherwise.
      $dbversion_stat = 0;
      if ( $dbvals[0] >= 5 && $dbvals[1] >= 1 && $dbvals[2] >= 5 ) {
         $dbversion_stat = 1;
      }
      $session->set('dbversion_stat', $dbversion_stat, 'PasswordControl');

      $exempt_users = $params->get('exempt_list', '');
      if (strlen($exempt_users)) {
         $exempt_users=array();
         $exempt_users = $this->explodeX(array(" ",","),$params->get('exempt_list'));
      }

      $change_within = $params->get( 'change_within', '9999' );
      $change_link = $params->get( 'change_link', 'index.php?option=com_users&amp;view=profile&amp;layout=edit' );
      $block_days = $params->get( 'block_days', '0' );
      $once_date = $params->get( 'once_date', '31-12-2029' );   // Last acceptable date.

      // Check specified $once_date and put into internal date format
      $once_date = strtotime(date("Y-m-d H:i:s", strtotime($once_date)));
      $once_date = date ( "Y-m-d H:i:s" , $once_date );
      $date = JFactory::getDate();
      if ($change_within >= $ndays) { $change_within = 9999; }  // Set to max if greater than ndays

      $euser = 0;
      // Only search for the user once in the array.
      if (is_array($exempt_users)) {
         $euser = in_array($userId, $exempt_users);
      }

      // forced_redirect   0 = no redirect, everything else means redirect
      if ( $params->get('forced_redirect', '0') == 0) { $forced_redirect = 0; }
      else { $forced_redirect = 1; }

      // User creation in our table is now performed in the onUserAfterSave routine
      // Still need to check if the user exists for the situation where the user was blocked
      // and if users were registering whilst we are installing.
      $db = & JFactory::getDBO();
      $this->check_exists($userId, $euser, $forcefirst, $once_date, $date, $ndays);

      // If the user is exempt or nothing to do just return at this point.
      if ($euser == 1 ) {
         $session->set('password_force', 0, 'PasswordControl');  // Any change these users make is always optional.
         return;
      }
      // Get current seq id of last password change entry.
      // We always save the last entry even if npwds is set to zero.  If that case we just do not check it.
      if ( $npwds < 2 ) {
         $seq_id = 0;
      } else {
         $query = "select seq_id from #__passwordcontrol where uid = " . (int) $userId . " ORDER BY last_password_change DESC LIMIT 1";
         $db->setQuery($query);
         $seq_id = $db->loadResult();
      }

      if ( $forcefirst == 0 && $ndays == 0 && $once_date > $date ) { return; }

      if ( $user->lastvisitDate == "0000-00-00 00:00:00" && ( $forcefirst == 1 || $once_date <= $date )) {
         // The user has never visited before
         if ( $option == $editProfileOption && $task == $editProfileSaveTask ) {
            // The user is saving their profile
            // Set the last visit date to a real value so they do not come through here again.
            $user->setLastVisit();
            $date = JFactory::getDate();
            $user->lastvisitDate = $date->toMySQL();

            $retn = $this->update_nextchange($userId, $date, $seq_id);
         } else if ( $option == $editProfileOption && $view == $editProfileView && empty($layout)) {
            // Capture the profile save complete and redirect if required  (No longer falls through here in v0.0.4)
            if ($forced_redirect == 1) { $app->redirect("index.php"); }
         } else if(!($option == $editProfileOption && $view == $editProfileView && $layout == $editProfileLayout)) {
            // The user is not on the edit profile form so redirect to edit profile after setting next change date is set to current date.
            $retn = $this->update_nextchange($userId, $date, $seq_id);
            $session->set('password_force', '1','PasswordControl');
            $session->set('password_forced_change', '1','PasswordControl');
            $app->redirect($change_link,JText::_('PLG_SYSTEM_PASSWORDCONTROL_FIRST_MSG'));
         }
      } else {
         // In the situation where forcefirst =0 and lastvistdate=0000-00-00 00:00:00 when they first login,
         // the lastVisitdate is updated and from then on the check below will capture them.
         // Chances are they have logged in before so check if they should change their password.
         // The onUserAfterSave event should update our table when they do.
         // This may be their first visit if forcefirst=0 and once_date is either not set or it is in the future.

         $query = $db->getQuery(true);
         $query->select('last_password_change, next_password_change, old_password')
            ->from('#__passwordcontrol')
            ->where('uid = '.(int) $userId . ' and seq_id = ' . $seq_id);
         $db->setQuery($query);
         $row = $db->loadRow();

         $last_change = $row[0];
         $next_change = $row[1];
         $old_pass    = $row[2];

         if ($last_change == "0000-00-00 00:00:00") {
            // Should never get this situation but IF we do, update the passwordcontrol table.
            $regdate = $user->registerDate;
            $ret = $this->update_lastchange($userId, $regdate, $seq_id);
            if (!$ret) { return false; }
            $last_change = $regdate;
         }

         $date = JFactory::getDate();   // Use Joomla date

         // Need to update the user if we have not set up next change date before, or
         // we have exceeded our one off change date.
         if ( ($next_change == "0000-00-00 00:00:00" && $ndays > 0) || ( $date >= $once_date && $last_change < $once_date )) {
            $next_change = $date;
            $retn = $this->update_nextchange($userId, $next_change, $seq_id);
         }

         // Need to enforce the password change.
         // forcefirst has already been handled and also we have handled a one off change (once_date) so that only leaves regular changes (ndays)
         if ( $ndays == 0 ) {          // forcefirst or once_date must be set since we checked earlier.
            // Allows for situation where we are only checking first change or
            // we have a one off change configured for all users (except exempt ones).
            if ( $option == $editProfileOption && $task == $editProfileSaveTask ) {
               // Allow the user to complete their save.
            } else if ( $option == $editProfileOption && $view == $editProfileView && empty($layout)) {
               // Capture the profile save complete and redirect if required
               $forced_change = $session->get('password_forced_change', '0','PasswordControl');
               $session->set('password_forced_change', '0','PasswordControl');
               if ( $forced_redirect == 1 && $forced_change == 1 ) { $app->redirect("index.php"); }
            } else if(!($option == $editProfileOption && $view == $editProfileView && $layout == $editProfileLayout)) {
               // If next change is never just return
               if ($next_change == "0000-00-00 00:00:00") { return; }

               if ($last_change > $next_change ) {
                  // Update DB to when they next need to change the password again,
                  // which when periodic changes are not enabled is never.
                  $retn = $this->update_nextchange($userId, '0000-00-00 00:00:00', $seq_id);
               } else if ( $next_change > $date ) {
                  return;          // Next change is in the future
               } else {
                  // Will fall through here if the admin changes a password for the user or a single one off change forced.
                  $invpwd = $session->get('password_invalid', '0','PasswordControl');
                  if ( $invpwd == 1 || ($next_change != '0000-00-00 00:00:00')) {
                     $retn = $this->update_nextchange($userId, '0000-00-00 00:00:00', $seq_id);
                     $session->set('password_forced_change', '1','PasswordControl');
                     $session->set('password_force', '1','PasswordControl');
                     $app->redirect($change_link, JText::_('PLG_SYSTEM_PASSWORDCONTROL_UPDATE_YOUR_PASSWORD'));
                  }
               }
            }
         } else {
            // ndays is set,  we may still have a one off change configured, but next change date should never be null.
            // On a fresh install however existing users will have the value of NULL.
            // It would also be NULL if the system admin had tried forcefirst before setting ndays
            // because then users would have already been created in the system.
            if ( $next_change == '0000-00-00 00:00:00' ) { return; }

            // Allow for situation where the user has optionally changed their password early.
            // What do here is just update the next change time as appropriate.
            // To detect this we need to check the next_change date to see if it is current.

            // This code is important since it reset the next date when we have formally had invalid password attempts
            // It also updates the next change date if we have an early password change (prompted or unprompted).
            $pwdchk = (int) $session->get('password_force', 0, 'PasswordControl');
            if ( $next_change >= $last_change && $ndays > 0 && $pwdchk == 0 ) {
               $chk_upd_date = strtotime(date("Y-m-d H:i:s", strtotime($last_change)) . " +".$ndays." days");
               $chk_upd_date = date ( "Y-m-d H:i:s" , $chk_upd_date );
               if ($chk_upd_date > $next_change && $chk_upd_date < $once_date ) {
                  $next_change = $chk_upd_date;
                  $retn = $this->update_nextchange($userId, $next_change, $seq_id);
               }
            }

            if ( $date < $next_change ) {
               $daystnc = (strtotime($next_change) - strtotime($date)) / (60 * 60 * 24);  // Days to next change
               if ( $daystnc <= $change_within ) {
                  $promptcheck = (int) $session->get('prompt_check','0','PasswordControl');
                  if ( $promptcheck == 0 ) {
                     $wdays = ceil ($daystnc);
                     // Time for a prompt
                     if ( $daystnc > 1 ) {
                        $message = preg_replace("/\r?\n/", "\\n", JText::_('PLG_SYSTEM_PASSWORDCONTROL_ADVANCED_WARN1').$wdays.JText::_('PLG_SYSTEM_PASSWORDCONTROL_ADVANCED_WARN2'));
                     } else {
                        $message = preg_replace("/\r?\n/", "\\n", JText::_('PLG_SYSTEM_PASSWORDCONTROL_ADVANCED_WARN1').$wdays.JText::_('PLG_SYSTEM_PASSWORDCONTROL_ADVANCED_WARN3'));
                     }
                     $session->set('password_forced_change', '1','PasswordControl');
?>
                     <script type="text/javascript">
                     <!--
                     var answer = confirm ("<?php echo $message; ?>")
                     if (answer)
                     {
                       window.location="<?php echo $change_link ;?>";
                     }
                     -->
                     </script>
<?php
                     // User has declined.  Set the session variable so they are not prompted again this session.
                     $session->set('password_forced_change', '0','PasswordControl');
                     $session->set('prompt_check', '1', 'PasswordControl');
                  }
               }
               $forced_change= $session->get('password_forced_change', '0','PasswordControl');
               $session->set('password_forced_change', '0','PasswordControl');    // Reset
               if ( $forced_redirect == 1 && $forced_change == 1 ) {
                  $app->redirect("index.php");
               }
               return;
            }
            // The user has exceeded our set limit for password changing
            // If we are blocking the user just tell them
            // Do not block if we are an administrator or making our initial change.
            $dayslc = (strtotime($date) - strtotime($last_change)) / (60 * 60 * 24);  // Days since last change

            if ( $dayslc > $block_days && $block_days > 0 && $last_change != $user->registerDate
               && !( $user->usertype == "Super Administrator" || $user->usertype == "Administrator" )) {
               // Also update the users details to block the account.
               // Set last visit date to null so when they are unblocked they are treated as a new user.
               $query = "UPDATE #__users SET block = 1, lastvisitdate='0000-00-00 00:00:00' WHERE id=".$userId;
               $db->setQuery($query);
               $ret = $db->query();
               if (!$ret) {
                  $app->enqueueMessage(nl2br($db->getErrorMsg()),'error');
                  print "<p>Database Error</p>";
                  print "<p>Error:".$db->getErrorNum()."-".$db->getErrorMsg()."</p>";
               }
?>
               <script type="text/javascript">
               <!--
                  alert ("<?php echo JText::_('PLG_SYSTEM_PASSWORDCONTROL_BLOCK_MSG') ;?>")
               -->
               </script>
<?php
               // Kill the current session and remove details from our table
               $session->destroy();
               $query = "DELETE from #__passwordcontrol WHERE uid=".$userId;
               $db->setQuery($query);
               $ret = $db->query();
               if (!$ret) {
                  $app->enqueueMessage(nl2br($db->getErrorMsg()),'error');
                  print "<p>Database Error</p>";
                  print "<p>Error:".$db->getErrorNum()."-".$db->getErrorMsg()."</p>";
               }
               $app->redirect("index.php");
            } else  if ($option == $editProfileOption && $task == $editProfileSaveTask) {
               // The user is saving their profile.  If they are changing the password then
               // it will be captured in the onUserAfterSave function. So do not need to do anything here.
               $user->setLastVisit();
            } else if ( $option == $editProfileOption && $view == $editProfileView && empty($layout)) {
               // Have they managed to save a password.
               $pforce = (int) $session->get('password_force', 0, 'PasswordControl');
               if ($pforce == 1 ) {
                  $session->set('password_forced_change', '1','PasswordControl');
                  $app->redirect($change_link,JText::_('PLG_SYSTEM_PASSWORDCONTROL_UPDATE_YOUR_PASSWORD'));
               }
               // Capture the profile save complete and redirect if required. This now works for initial and subsequent changes.
               // The extra condition checks whether we forced then or not.
               $forced_change= $session->get('password_forced_change', '0','PasswordControl');
               $session->set('password_forced_change', '0','PasswordControl');    // Reset
               if ($forced_redirect == 1 && $forced_change == 1 ) {
                  $app->redirect("index.php");
               }
            } else if(!($option == $editProfileOption && $view == $editProfileView && $layout == $editProfileLayout)) {
               // They also come in here after the save when the password may not have changed.
               if ( $last_change >= $next_change ) {
                  // Update DB to not prompt until next required date
                  if ( $ndays > 0 ) {
                     $date = JFactory::getDate();
                     $ndate = strtotime(date("Y-m-d H:i:s", strtotime($date)) . " +".$ndays." days");
                     $ndate = date ( "Y-m-d H:i:s" , $ndate );
                  } else {                    // Must have a one off change
                     $ndate = "0000-00-00 00:00:00";
                  }
                  $retn = $this->update_nextchange($userId, $ndate, $seq_id);
               } else {
                  $invpwd = (int) $session->get('password_invalid', 0, 'PasswordControl');
                  $pwdfrc = (int) $session->get('password_force', '1', 'PasswordControl');
                  if ( $invpwd == 0 && $pwdfrc == 0 ) {
                     return;
                  }
                  // do not set password_force if we are changing early !
                  if ( $date >= $next_change ) {
                     $session->set('password_force', '1', 'PasswordControl');
                     if ( $invpwd == 1 ) {
                        $message = preg_replace("/\r?\n/", "\\n", JText::_('PLG_SYSTEM_PASSWORDCONTROL_INVALID_MSG2'));
                        $session->set('password_forced_change', '1','PasswordControl');
                        $app->redirect($change_link,JText::_($message));
                     } else {
                        $session->set('password_forced_change', '1','PasswordControl');
                        $app->redirect($change_link,JText::_('PLG_SYSTEM_PASSWORDCONTROL_UPDATE_YOUR_PASSWORD'));
                     }
                  } else {
                     return;
                  }
               }
            }
         }
      }
   }


   function onUserAfterSave($data, $isNew, $result, $error)
   {
      // User or administrator has saved their data.  We need to check whether the password
      // was changed and if so update the passwordcontrol table.

      $userId  = JArrayHelper::getValue($data, 'id', 0, 'int');  // User being changed.

      if ($userId && $result) {
         $chk=$data[password_clear];
         // Check if a password change has occurred.  Return if no password specified.
         if (!isset($data[password_clear])) { return true; }

         $session = JFactory::getSession();
         // Check whether we know the parameters yet?  If the first change is
         // by the administrator the parameters will not be set.
         $db = JFactory::getDbo();
         $app = & JFactory::getApplication();

         // If the parameters we want have already been processes get them from the session
         // otherwise we need to read them directly
         $paramsset = $session->get('paramsset', 0, 'PasswordControl');
         if ( $paramsset == 0 ) {
            $vals = $this->whichparams();
            $params = $vals[0];
            $ndays  = $params->get('ndays','0');
            $npwds  = $params->get('npwds','1');
            $dbchks = $params->get('dbchecks','0');

            $dbver = $this->check_dbversion();
            if (strlen($dbver)) {
               $dbvals = array();
               $dbvals = $this->explodeX(array(".","-"),$dbver);
            }
            // Check if we need the binlog_format settings  1 says yes we do, 0 otherwise.
            $dbversion_stat = 0;
            if ( $dbvals[0] >= 5 && $dbvals[1] >= 1 && $dbvals[2] >= 5 ) {
               $dbversion_stat = 1;
            }
         } else {
            $npwds  = (int) $session->get('npasswords', 1, 'PasswordControl');
            $ndays  = (int) $session->get('periodicity', 0, 'PasswordControl');
            $dbchks = (int) $session->get('dbchecks', 0, 'PasswordControl');
            $dbversion_stat = (int) $session->get('dbversion_stat', $dbversion_stat, 'PasswordControl');
         }

         $cuser = & JFactory::getUser();  // Current user
         $cuserId = $cuser->id;           // Current userid

         if ( $npwds > 0 ) {
            // We want to check passwords.
            if ( $dbchks == 1 ) {
               // Call database checking routine here.  0 is false 1 is true
               if ( $dbversion_stat == 1 ) {
                  $db->setQuery("SET SESSION binlog_format = 'MIXED'");
                  $retn = $db->query();
                  if (!$retn) {         // Capture return in case the statement fails.
                     $app->enqueueMessage(nl2br($db->getErrorMsg()),'error');
                     print "<p>Database Error</p>";
                     print "<p>Error:".$db->getErrorNum()."-".$db->getErrorMsg()."</p>";
                     return false;
                  }
               }
               $query = 'SELECT #__oldpasswordcheck ( ' . $userId . ',"' . $chk . '")';
               $db->setQuery($query);
               $ret = $db->loadResult();
            } else {
               // PHP password checking routine call
               $ret = $this->check_passwd($userId, $chk, $npwds);
            }

            if ( $ret ) {
               $session->set('password_invalid', '1', 'PasswordControl');
               if ( $app->isAdmin() ) {
                  throw new Exception(JText::_('PLG_SYSTEM_PASSWORDCONTROL_PREVIOUS_ADMIN_MSG'));
               } else {
                  throw new Exception(JText::_('PLG_SYSTEM_PASSWORDCONTROL_PREVIOUS_USER_MSG'));
               }
               return;  // Same password, this statement should not be reached.
            }
         }
         $session->set('password_invalid', '0', 'PasswordControl');

         // This is a different password (or we do not care) so update our table.
         $date = JFactory::getDate();
         if ( $ndays > 0 ) {
            $ndate = strtotime(date("Y-m-d H:i:s", strtotime($date)) . " +".$ndays." days");
            $ndate = date ( 'Y-m-d H:i:s' , $ndate );
         } else {
            $ndate = "0000-00-00 00:00:00";
         }
         if ( $app->isAdmin() && $cuserId != $userId ) {
            // If we are an admin user and we are not changing ourselves, force a change on next user connection.
            $cuser = & JFactory::getUser();
            $ndate = strtotime(date("Y-m-d H:i:s", strtotime($date)) . " + 1 second");
            $ndate = date ( 'Y-m-d H:i:s' , $ndate );
         }

         // Call database routine here.  0 is false 1 is true
         $query = 'CALL #__updcontroltable (' . $userId . ',"' . $date . '","' . $ndate . '","' . $data[password] . '",' . $npwds . ')';
         $db->setQuery($query);
         $retn= $db->query();   // Returns false if query fails to execute

         if (!$retn) {
            $app->enqueueMessage(nl2br($db->getErrorMsg()),'error');
            print "<p>Database Error</p>";
            print "<p>Error:".$db->getErrorNum()."-".$db->getErrorMsg()."</p>";
            return false;
         }
         $session->set('password_force', '0','PasswordControl');
      }
      return true;
   }

   function check_dbversion ()
   {
      if (empty($db)) { $db = & JFactory::getDBO(); }
      $db->setQuery('select version()');
      $ret = $db->query();
      if (!$ret) {
         $app->enqueueMessage(nl2br($db->getErrorMsg()),'error');
         print "<p>Database Error</p>";
         print "<p>Error:".$db->getErrorNum()."-".$db->getErrorMsg()."</p>";
      }
      $version = $db->loadResult();
      return $version;
   }

   function whichparams ()
   {
      $retval = array();
      $app = JFactory::getApplication();
      if (empty($db)) { $db = & JFactory::getDBO(); }
      $db->setQuery('SELECT count(name) FROM #__extensions WHERE name = "com_passwordcontrol"');
      $rowexists = $db->loadResult();
      if ( $rowexists == "1" ) {
         // Get setting from the component parameters
         $retval [] = $app->getParams('com_passwordcontrol');
         $retval [] = 'component';
      } else {
         // Get settings from plugin parameters
         $retval [] = new JParameter(JPluginHelper::getPlugin('system', 'passwordcontrol')->params);
         $retval [] = 'plugin';
      }
      return $retval;
   }

   function check_passwd ($userId, $pass, $npwds)
   {
      // $pass is the new password in the clear that they entered.
      jimport('joomla.user.helper');

      if ( $npwds == 0 ) { return false; }  // We do not want to check passwords.

      if (empty($db)) { $db = & JFactory::getDBO(); }

      if ( $npwds == 1 ) {
         $query = $db->getQuery(true);
         $query->select('old_password')
            ->from('#__passwordcontrol')
            ->where('uid = '.(int) $userId . ' and seq_id = 0' );
         $db->setQuery($query);
         $ret = $db->query();

         $opasswd = $db->loadResult();

         $pieces = explode (":", $opasswd);
         // Extract the old salt that we used.
         $salt = $pieces[1];
         $ocrypt = $pieces[0];
         $crypt = JUserHelper::getCryptedPassword($pass, $salt);

         // If the same return true.
         if ($crypt == $ocrypt) { return true; }
      } else {
         $query = 'SELECT seq_id, old_password from #__passwordcontrol where uid = '.(int) $userId;
         $db->setQuery($query);
         $db->query();
         $num_rows = $db->getNumRows();
         $row = $db->loadRowList();

         for ( $i = 0; $i < $num_rows; $i++ ) {
            $pieces = explode (":", $row[$i][1]);
            // Extract the old salt that we used.
            $salt = $pieces[1];
            $ocrypt = $pieces[0];
            $crypt = JUserHelper::getCryptedPassword($pass, $salt);

            // If the same return true.
            if ($crypt == $ocrypt) { return true; }
         }
      }
      return false;      // Else return false
   }

   function check_exists($userId, $euser, $forcefirst, $once_date, $date, $ndays)
   {
      if (empty($db)) { $db = & JFactory::getDBO(); }
      $query = $db->getQuery(true);
      $query = "SELECT count(id) from #__passwordcontrol where uid=".$userId;
      $db->setQuery($query);
      $results = $db->loadResult();

      if ($results == 0) {
         // If forceforce = 1 set next change date to now.
         // If ndays greater than zero set change date to ndays time.
         // If one off change set change date to now or the specified date if in the future unless forcefirst is 1
         // Else set next change date to never i.e.  default 0000-00-00 00:00:00
         // Note that the last_password_change will be in Joomla time, so we need to ensure the next_password_change is in Joomla time.
         // Even if user is exempt we keep the entry so that the onAfterUserSave function does not complain.
         if ($euser == 1) {
            $query = "INSERT INTO #__passwordcontrol (uid, last_password_change, old_password) SELECT id, registerDate, password from #__users where #__users.id = ".$userId;
         } elseif ($forcefirst == 1) {
            $query = "INSERT INTO #__passwordcontrol (uid, last_password_change, next_password_change, old_password) SELECT id, registerDate, '".$date."', password from #__users where #__users.id = ".$userId;
         } elseif ( $once_date >= $date ) {
            $query = "INSERT INTO #__passwordcontrol (uid, last_password_change, next_password_change, old_password) SELECT id, registerDate, '".$once_date."', password from #__users where #__users.id = ".$userId;
         } elseif ($ndays > 0) {
            // Calculate next change date in Joomla time.
            $ndate = strtotime(date("Y-m-d H:i:s", strtotime($date)) . " +".$ndays." days");
            $ndate = date ( 'Y-m-d H:i:s' , $ndate );
            $query = "INSERT INTO #__passwordcontrol (uid, last_password_change, next_password_change, old_password) SELECT id, registerDate, '".$ndate."', password from #__users where #__users.id = ".$userId;
         } else {
            $query = "INSERT INTO #__passwordcontrol (uid, last_password_change, old_password) SELECT id, registerDate, password from #__users where #__users.id = ".$userId;
         }
         $db->setQuery($query);
         $ret = $db->query();
         if (!$ret) {
            $app->enqueueMessage(nl2br($db->getErrorMsg()),'error');
            print "<p>Database Error</p>";
            print "<p>Error:".$db->getErrorNum()."-".$db->getErrorMsg()."</p>";
         }
      }
      return;
   }

   function update_lastchange($uid, $udate, $seqid)
   {
      if (empty($db)) { $db = & JFactory::getDBO(); }
      $query = 'UPDATE #__passwordcontrol SET last_password_change = "' . $udate . '" WHERE uid=' . $uid . ' AND seq_id = ' . $seqid;
      $db->setQuery($query);
      $ret = $db->query();
      if (!$ret) {
         $app->enqueueMessage(nl2br($db->getErrorMsg()),'error');
         print "<p>Database Error</p>";
         print "<p>Error:".$db->getErrorNum()."-".$db->getErrorMsg()."</p>";
         return false;
      }
      return true;
   }

   function update_nextchange($uid, $udate, $seqid)
   {
      if (empty($db)) { $db = & JFactory::getDBO(); }
      $query = 'UPDATE #__passwordcontrol SET next_password_change = "' . $udate . '" WHERE uid=' . $uid . ' AND seq_id = ' . $seqid;
      $db->setQuery($query);
      $ret = $db->query();
      if (!$ret) {
         $app->enqueueMessage(nl2br($db->getErrorMsg()),'error');
         print "<p>Database Error</p>";
         print "<p>Error:".$db->getErrorNum()."-".$db->getErrorMsg()."</p>";
         return false;
      }
      return true;
   }

   function explodeX($delimiters,$string)
   {
      $return_array = Array($string); // The array to return
      $d_count = 0;
      while (isset($delimiters[$d_count])) // Loop to loop through all delimiters
      {
         $new_return_array = Array();
         foreach($return_array as $el_to_split) // Explode all returned elements by the next delimiter
         {
            $put_in_new_return_array = explode($delimiters[$d_count],$el_to_split);
            foreach($put_in_new_return_array as $substr) // Put all the exploded elements in array to return
            {
               $new_return_array[] = $substr;
            }
         }
         $return_array = $new_return_array; // Replace the previous return array by the next version
         $d_count++;
      }
      return $return_array; // Return the exploded elements
   }
}