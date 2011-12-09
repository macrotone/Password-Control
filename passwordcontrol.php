<?php
/**
 * @version        passwordcontrol.php 0.0.3
 * @package        Password Control
 * @copyright      (C) 2011 Macrotone Consulting Ltd - All rights reserved
 * @Website        http://www.macrotone.co.uk
 * @license        GNU Public License version 2 or above.  See LICENSE.txt
 */

// no direct access
defined('_JEXEC') or die('Restricted access');

jimport('joomla.plugin.plugin');

class plgSystemPasswordControl extends JPlugin
{
    function plgSystemPasswordControl(&$subject, $config)
	{
		parent::__construct($subject, $config);
		$this->loadLanguage();
	}

	function onContentPrepareForm($form, $data){
		if (!($form instanceof JForm)) {
			$this->_subject->setError('JERROR_NOT_A_FORM');
			return false;
		}

		// Only display on supported forms, otherwise just return
		if(!($form->getName() == 'com_users.profile' && JRequest::getVar('layout') == 'edit'))
        {
			return true;
		}

        $app = & JFactory::getApplication();
        if( $app->isAdmin() || JDEBUG ) { return; }

        $user = & JFactory::getUser();
        if ( $user->guest ) { return; }

        $session = JFactory::getSession();
        $passwordforce = (int) $session->get('password_force',0,'PasswordControl');

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

        // If admin backend or debugging return
        if ( $app->isAdmin() || JDEBUG ) { return; }

        $user = & JFactory::getUser();

        // If a guest just return.
        if ( $user->guest ) { return; }

        $session = JFactory::getSession();

        $lang =  & JFactory::getLanguage();
  		$lang->load('plg_system_passwordcontrol', JPATH_ADMINISTRATOR);

		// Set up defaults
		$option = JRequest::getCmd('option');
		$view = JRequest::getVar('view');
		$task = JRequest::getVar('task');
		$layout = JRequest::getVar('layout');

		$editProfileOption = "com_users";
		$editProfileLayout = "edit";
		$editProfileSaveTask = "profile.save";
		$editProfileView = "profile";

        $userId = $user->id;

        // get settings from passwordcontrol parameters
		$params 	= new JParameter(JPluginHelper::getPlugin('system', 'passwordcontrol')->params);

        // forcefirst 0 = no, everything else means force password change on first logon
		if ( $params->get('forcefirst', '0') == 0) { $forcefirst = 0; }
		else { $forcefirst = 1; }

        // firstredirect 0 = no firstredirect, everything else means redirect
		if ( $params->get('firstredirect', '0') == 0) { $firstredirect = 0; }
		else { $firstredirect = 1; }

        $ndays = $params->get('ndays','0');
        if ( $ndays > 0 ) { $session->set('periodicity', $ndays, 'PasswordControl'); }

        $exempt_users = $params->get('exempt_list', '');
        if(strlen($exempt_users)) {
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
        if ( $once_date < $date ) { $once_date = $date; }    // If less than current set change to current
        if ($change_within >= $ndays) { $change_within = 9999; }  // Set to max if greater than ndays

        // Only search for the user once in the array.
        $euser = in_array($userId, $exempt_users);

        // subredirect   0 = no redirect, everything else means redirect
        if ( $params->get('subredirect', '0') == 0) { $subredirect = 0; }
		else { $subredirect	= 1; }

		// If a new user do we have them in our control table?  If not we need to add them!
		$db	= & JFactory::getDBO();
		$sql = $db->getQuery(true);
		$sql = "SELECT count(id) from #__passwordcontrol where uid=".$userId;
		$db->setQuery($sql);
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

        // If the user is exempt or nothing to do just return at this point.
        if ($euser == 1 ) {
           $session->set('password_force', 0, 'PasswordControl');  // Any change these users make is always optional.
           return;
        }

        if ( $forcefirst == 0 && $ndays == 0 && $once_date > $date ) { return; }

        if ( $user->lastvisitDate == "0000-00-00 00:00:00" && ( $forcefirst == 1 || $once_date <= $date ))
		{
			// The user has never visited before
			if( $option == $editProfileOption && $task == $editProfileSaveTask )
			{
				// The user is saving their profile
				// Set the last visit date to a real value so they do not come through here again.
				$user->setLastVisit();
			    $date = JFactory::getDate();
				$user->lastvisitDate = $date->toMySQL();

                $retn = $this->update_nextchange($userId, $date);
			} else if ( $option == $editProfileOption && $view == $editProfileView && empty($layout))
			{
				// Capture the profile save complete and redirect if required
			    if ($firstdirect == 1) { $app->redirect("index.php"); }

			} else if(!($option == $editProfileOption && $view == $editProfileView && $layout == $editProfileLayout))
			{
				// Ensure next change date is set to current date.
				$retn = $this->update_nextchange($userId, $date);

				// The user is not on the edit profile form so redirect to edit profile
	            $session->set('password_force', '1','PasswordControl');
?>
                <script type="text/javascript">
                <!--
                   alert ("<?php echo JText::_('PLG_SYSTEM_PASSWORDCONTROL_FIRST_MSG') ;?>")
                   window.location="<?php echo $change_link ;?>";
                -->
                </script>
<?php
			}
		}
		// In the situation where forcefirst =0 and lastvistdate=0000-00-00 00:00:00 when they first login,
		// the lastVisitdate is updated and from then on the check below will capture them.
        else {
            // Chances are they have logged in before so check if they should change their password.
		    // The onUserAfterSave event should update our table when they do.
		    // This may be their frst visit if forcefirst=0 and once_date is either not set or it is in the future.

		    $sql = $db->getQuery(true);
		    $sql->select('last_password_change, next_password_change, old_password')
		        ->from('#__passwordcontrol')
		        ->where('uid = '.(int) $userId);
		    $db->setQuery($sql);
            $row = $db->loadRow();

 		    $last_change = $row[0];
  		    $next_change = $row[1];
 		    $old_pass = $row[2];

  		    if ($last_change == "0000-00-00 00:00:00") {
  		       // Should never get this situation but IF we do, update the passwordcontrol table.
 		       $regdate = $user->registerDate;
               $ret = $this->update_lastchange($userId, $regdate);
               if (!$ret) { return false; }
  		       $last_change = $regdate;
 		    }

 		    $date = JFactory::getDate();   // Use Joomla date

            // Need to update the user if we have not set up change date before, or
            // we have exceeded our one off change date.
            if ( $next_change == "0000-00-00 00:00:00" || $date >= $once_date )
            {
                if ( $date >= $once_date ) {
                   $next_change = $once_date;
				} else {
				   $next_change = $date;
				}
				$retn = $this->update_nextchange($userId, $next_change);
            }

            // Need to enforce the password change.
            // forcefirst has already been handled and also we have handled a one off change (once_date) so that only leaves regular changes (ndays)

            if ($ndays == 0)          // forcefirst or once_date must be set since we checked earlier.
            {
                // Allows for situation where we are only checking first change or
                // we have a one off change configured for all users (except exempt ones).
                if( $option == $editProfileOption && $task == $editProfileSaveTask )
				{
				   // Allow the user to complete their save.
				} elseif ( $option == $editProfileOption && $view == $editProfileView && empty($layout))
				{
				   // Capture the profile save complete and redirect if required
				   if ($firstredirect == 1) { $app->redirect("index.php"); }
				} elseif(!($option == $editProfileOption && $view == $editProfileView && $layout == $editProfileLayout))
				{
				    // If next change is never just return
				    if ($next_change == "0000-00-00 00:00:00") { return; }

			        if ($last_change > $next_change ) {
					    // Update DB to when they next need to change the password again,
					    // which when periodic changes are not enabled is never.
						$retn = $this->update_nextchange($userId, '0000-00-00 00:00:00');
					} else if ( $next_change > $date ) {
					    return;          // Next change is in the future
					} else {
						$session->set('password_force', '1','PasswordControl');
						$app->redirect($change_link, JText::_('PLG_SYSTEM_PASSWORDCONTROL_UPDATE_YOUR_PASSWORD'));
					}
			    }
            } else {
                // ndays is set,  we may still have a one off change configured, but next change date should never be null.
                // On a fresh install however existing users will have the value of NULL.
                // It would also be NULL if the system admin had tried forcefirst before setting ndays
                // because then users would have already been created in the system.

                if ( $next_change == '0000-00-00 00:00:00') { return; }

                // Also have the situation where the user has optionally changed their password early without
			    // us prompting.  What do here is just update the next change time as appropriate.
			    // To detect this we need to check the next_change date to see if it is current.
			    // Might need to allow a few minutes either side here.  We will see.
			    if ($next_change > $last_change && $ndays > 0 ) {
			       $chk_upd_date = strtotime(date("Y-m-d H:i:s", strtotime($last_change)) . " +".$ndays." days");
			       $chk_upd_date = date ( "Y-m-d H:i:s" , $chk_upd_date );
			       if ($chk_upd_date > $next_change ) {
  				      $next_change = $chk_upd_date;
				      $retn = $this->update_nextchange($userId, $next_change);
				   }
				}

                $days = (strtotime($last_change) - strtotime($date)) / (60 * 60 * 24);
                if( $days < 0 ) {
                    $days = $days*-1;
                }

                if ( $date < $next_change ) {
                   if ( $ndays-$days <= $change_within ) {

                      $promptcheck = (int) $session->get('prompt_check','0','PasswordControl');
                      if ( $promptcheck == 0 ) {
                         $wdays = ceil ($ndays - $days);
                         // Time for a prompt
                         $message = preg_replace("/\r?\n/", "\\n", JText::_('PLG_SYSTEM_PASSWORDCONTROL_ADVANCED_WARN1').$wdays.JText::_('PLG_SYSTEM_PASSWORDCONTROL_ADVANCED_WARN2'));
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
                      $session->set('prompt_check', '1', 'PasswordControl');
                      }
                   }
                   return;    // Not time for password change or a prompt yet!
                }

                // The user has exceeded our set limit for password changing
                // If we are blocking the user just tell them
                // Do not block if we are an administrator or making our initial change.
                if ( $days > $block_days && $block_days > 0 && $last_change != $user->registerDate
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
                    // Kill the current session
                    $session->destroy();
                    // Remove details from our table
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
			        // it will be captured in the onUserAfterSave function.
			        // So do not need to do anything here, but we will update the last visit date to a real value.
			        $user->setLastVisit();
			        $date = JFactory::getDate();
			        $user->lastvisitDate = $date->toMySQL();
 			        $invpwd = (int) $session->get('password_invalid', 0, 'PasswordControl');
 			        $pwdchk = (int) $session->set('password_force', 0, 'PasswordControl');
 			        if ( $invpwd == 1 && $pwdchk == 1) {
 			           $message = preg_replace("/\r?\n/", "\\n", JText::_('PLG_SYSTEM_PASSWORDCONTROL_NOTCHANGED_MSG'));
 	                   $message = "A Password change was requested but not supplied. You are being redirected to change your password.";
?>
		               <script type="text/javascript">
 			           <!--
  			              alert ("<?php echo $message; ?>")
  			           -->
   		               </script>
<?php
                       $app->redirect($change_link,JText::_('PLG_SYSTEM_PASSWORDCONTROL_UPDATE_YOUR_PASSWORD'));
                    }
				} else if ( $option == $editProfileOption && $view == $editProfileView && empty($layout)) {
				    // Capture the profile save complete and redirect if required
				    if ($subredirect == 1) { $app->redirect("index.php"); }
			    } else if(!($option == $editProfileOption && $view == $editProfileView && $layout == $editProfileLayout)) {
			        // They may also come in here after the save when the password has not changed.

	                if ($last_change > $next_change ) {
			           // Update DB to not prompt until next required date
					   if ( $ndays > 0 ) {
					      $ndate = strtotime("+".$ndays." days");
                          $ndate = date ( "Y-m-d H:i:s" , $ndate );
                       } else {                    // Must have a one off change
                          $ndate = "0000-00-00 00:00:00";
                       }
                       $retn = $this->update_nextchange($userId, $ndate);
	               } else {
 	                   $invpwd = (int) $session->get('password_invalid', 0, 'PasswordControl');
 	                   $session->set('password_force', '1', 'PasswordControl');
 	                   if ( $invpwd == 1 ) {
 	                      $message = preg_replace("/\r?\n/", "\\n", JText::_('PLG_SYSTEM_PASSWORDCONTROL_INVALID_MSG'));
?>
  			             <script type="text/javascript">
  			             <!--
  			                alert ("<?php echo $message; ?>")
  			             -->
   		                 </script>
<?php
 	                   }
 					   $app->redirect($change_link,JText::_('PLG_SYSTEM_PASSWORDCONTROL_UPDATE_YOUR_PASSWORD'));
			       }
			    }
			}
		}
	}

	function onUserAfterSave($data, $isNew, $result, $error)
	{
	   // User has saved their data.  We need to check whether the password
	   // was changed and if so update the passwordcontrol table.

  	   $userId	= JArrayHelper::getValue($data, 'id', 0, 'int');

	   if ($userId && $result)
	   {
	      $chk=$data[password_clear];
	      // Check if a password change has occured.
//	      if (is_null($data[password_clear])) { die("Null password."); return true; }
          // For some unknown reason is_null is not working correctly so check directly.
          // Return if no password specified.

          if ($chk == NULL) { return true; }
	      else {
	         // Get password from our table.
	         $db  =& JFactory::getDBO();
		     $sql = $db->getQuery(true);
			 $sql->select('old_password')
		         ->from('#__passwordcontrol')
			     ->where('uid = '.(int) $userId);
			 $db->setQuery($sql);

			 $opasswd = $db->loadResult();
			 $ret = $this->check_passwd($data[password1], $opasswd);

 	         $session = JFactory::getSession();
	         if ( $ret ) {
                $session->set('password_invalid', '1', 'PasswordControl');
                return;  // Same password, they still have to change it.
	         } else {
	            $session->set('password_invalid', '0', 'PasswordControl');
	            // This is a different password so update our table.
	            $date = JFactory::getDate();
                $ndays = (int) $session->get('periodicity', 0, 'PasswordControl');
                if ( $ndays > 0 ) {
                   $ndate = strtotime(date("Y-m-d H:i:s", strtotime($date)) . " +".$ndays." days");
                   $ndate = date ( 'Y-m-d H:i:s' , $ndate );
                } else {
                   $ndate = "0000-00-00 00:00:00";
                }
 	            $passwd = $data[password];
 	            $retn = $this->update_passwordcontrol2($userId, $date, $ndate, $passwd);

 	            if (!$retn) { return false; }
                $session->set('password_force', '0','PasswordControl');
	         }
	      }
	   }
	   return true;
	}

	function check_passwd ($pass, $cpass)
    {
    	// $pass is the password in the clear that they entered.
    	// $cpass is the encrypted password we had last time.
    	jimport('joomla.user.helper');

        $pieces = explode (":", $cpass);
    	// Extract the old salt that we used.
    	$salt = $pieces[1];
    	$ocrypt = $pieces[0];
        $crypt = JUserHelper::getCryptedPassword($pass, $salt);

        // If the same return true.
        if ($crypt == $ocrypt) { return true; }
           // Else return false.
        return false;
    }

    function update_lastchange($uid, $udate)
    {
       $db	=& JFactory::getDBO();
       $sql = $db->getQuery(true);
       $query = "UPDATE #__passwordcontrol SET last_password_change = '".$udate."' WHERE uid=".$uid;
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

    function update_nextchange($uid, $udate)
	{
	   $db	=& JFactory::getDBO();
	   $sql = $db->getQuery(true);
	   $query = "UPDATE #__passwordcontrol SET next_password_change = '".$udate."' WHERE uid=".$uid;
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

    function update_passwordcontrol2($uid, $udate, $ndate, $pass)
	{
	   $db	=& JFactory::getDBO();
	   $sql = $db->getQuery(true);
	   if ( $ndate == "0000-00-00 00:00:00" ) {
 	      $query = "UPDATE #__passwordcontrol SET old_password = '".$pass."', last_password_change = '".$udate."' WHERE uid=".$uid;
 	   } else {
 	      $query = "UPDATE #__passwordcontrol SET old_password = '".$pass."', last_password_change = '".$udate."', next_password_change = '".$ndate."' WHERE uid=".$uid;
 	   }
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
