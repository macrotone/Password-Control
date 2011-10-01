<?php
/**
 * @version        passwordcontrol.php 0.0.2
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

	function onAfterRoute()
	{
        $app = & JFactory::getApplication();

        // If admin backend or debugging return
        if($app->isAdmin() || JDEBUG) { return; }

        $user = & JFactory::getUser();

        // If a guest just return.
        if ($user->guest) { return; }

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

        // forcefirst 0 = no forcefirst, everything else means force password change on first logon
		if ( $params->get('forcefirst', '0') == 0) { $forcefirst = 0; }
		else { $forcefirst = 1; }

        // firstredirect 0 = no firstredirect, everything else means redirect
		if ( $params->get('firstredirect', '0') == 0) { $firstredirect = 0; }
		else { $firstredirect = 1; }

        if ( $params->get('existingusers', '0') == 0) { $upd_existing_users = 0; }
		else { $upd_existing_users = 1; }

        $ndays = $params->get('ndays','0');

        $exempt_users = $params->get('exempt_list', '');
        if(strlen($exempt_users)) {
		     $exempt_users=array();
		     $exempt_users = $this->explodeX(array(" ",","),$params->get('exempt_list'));
		}

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

        $date  = JFactory::getDate();

        if ($results == 0) {
            // If forceforce = 1 set next change date to now.
            // If ndays greater than zero set change date to ndays time.
            // Else set next change date to never i.e.  default 0000-00-00 00:00:00
            // Note that the last_password_change will be in Joomla time, so we need to ensure the next_password_change is in Joomla time.
            // Even if user is exempt we keep the entry so that the onAfterUserSave function does not complain.
            if ($euser == 1) {
                 $query = "INSERT INTO #__passwordcontrol (uid, last_password_change, old_password) SELECT id, registerDate, password from #__users where #__users.id = ".$userId;
            } elseif ($forcefirst == 1) {
                $query = "INSERT INTO #__passwordcontrol (uid, last_password_change, next_password_change, old_password) SELECT id, registerDate, '".$date."', password from #__users where #__users.id = ".$userId;
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

        // If the user is exempt just return at this point.
        if ($euser == 1) return true;

        if ($forcefirst == 0 && $ndays == 0 ) { return; }

        if($forcefirst == 1 && $user->lastvisitDate == "0000-00-00 00:00:00")
		{
			// The user has never visited before
			if($option == $editProfileOption && $task == $editProfileSaveTask)
			{
				// The user is saving their profile
				// Set the last visit date to a real value so they do not come through here again.
				$user->setLastVisit();
			    $date = JFactory::getDate();
				$user->lastvisitDate = $date->toMySQL();

				$query = "UPDATE #__passwordcontrol SET next_password_change = '".$date."' WHERE uid=".$userId;
				$db->setQuery($query);
				$ret = $db->query();
				if (!$ret) {
				   $app->enqueueMessage(nl2br($db->getErrorMsg()),'error');
				   print "<p>Database Error</p>";
                   print "<p>Error:".$db->getErrorNum()."-".$db->getErrorMsg()."</p>";
				}
			} else if ( $option == $editProfileOption && $view == $editProfileView && empty($layout))
			{
				// Capture the profile save complete and redirect if required
			    if ($firstdirect == 1) { $app->redirect("index.php"); }

			} else if(!($option == $editProfileOption && $view == $editProfileView && $layout == $editProfileLayout))
			{
				// Ensure next change date is set to current date.
				$query = "UPDATE #__passwordcontrol SET next_password_change = '".$date."' WHERE uid=".$userId;
				$db->setQuery($query);
                $ret = $db->query();
                if (!$ret) {
                   $app->enqueueMessage(nl2br($db->getErrorMsg()),'error');
				   print "<p>Database Error</p>";
                   print "<p>Error:".$db->getErrorNum()."-".$db->getErrorMsg()."</p>";
                }

				// The user is not on the edit profile form so redirect to edit profile
				$lang =  & JFactory::getLanguage();
				$lang->load('plg_system_passwordcontrol', JPATH_ADMINISTRATOR);

                $app->redirect(
					"index.php?option=".$editProfileOption."&view=".$editProfileView."&layout=".$editProfileLayout,
					JText::_('PLG_SYSTEM_PASSWORDCONTROL_UPDATE_YOUR_PASSWORD'));
			}
		}
		// What if forcefirst =0 and lastvistdate=0000-00-00 00:00:00  ?
		// In this situation when they first login, the lastVisitdate is updated and from then on
		// the check below will capture them.

        else {

            // They have logged in before so check if they should change their password.
		    // The onUserAfterSave event should update our table when they do.

		    $sql = $db->getQuery(true);
		    $sql->select('last_password_change, next_password_change, old_password')
		        ->from('#__passwordcontrol')
		        ->where('uid = '.(int) $userId);
		    $db->setQuery($sql);
            $row = $db->loadRow();

 		    $ldate = $row[0];
  		    $next_change = $row[1];
 		    $old_pass = $row[2];

  		    if ($ldate == "0000-00-00 00:00:00") {
  		       // Should never get this situation but IF we do, update the passwordcontrol table.
 		       $regdate = $user->registerDate;
               $ret = $this->update_passwordcontrol($userId, $regdate);
               if (!$ret) { return false; }
  		       $ldate = $regdate;
 		    }

           // Need to update the user and we have not set up change date before.
           if ( $upd_existing_users == 1 && $next_change == "0000-00-00 00:00:00" ) {
                $next_change = $date;
                $query = "UPDATE #__passwordcontrol SET next_password_change = '".$next_change."' WHERE uid=".$userId;
				$db->setQuery($query);
				$ret = $db->query();
				if (!$ret) {
				    $app->enqueueMessage(nl2br($db->getErrorMsg()),'error');
				    print "<p>Database Error</p>";
				    print "<p>Error:".$db->getErrorNum()."-".$db->getErrorMsg()."</p>";
				}
            }

             // Need to enforce the password change.

            if ($ndays == 0)                 // forcefirst must be set since we checked earlier.
            {
                if ( $next_change == '0000-00-00 00:00:00') { return; }

                // Allows for situation where we are only checking first change.
                if($option == $editProfileOption && $task == $editProfileSaveTask)
				{
				   // Allow the user to complete their save.
				} elseif ( $option == $editProfileOption && $view == $editProfileView && empty($layout))
				{
				   // Capture the profile save complete andredirect if required
				   if ($firstredirect == 1) { $app->redirect("index.php"); }

				} elseif(!($option == $editProfileOption && $view == $editProfileView && $layout == $editProfileLayout))
				{
				    // If next change is never so just return
				    if ($next_change == "0000-00-00 00:00:00") { return; }

			        if ($ldate > $next_change ) {
					    // Update DB to when they next need to change the password again
					    //$db	=& JFactory::getDBO();
						//$sql = $db->getQuery(true);
					    $query = "UPDATE #__passwordcontrol SET next_password_change = '0000-00-00 00:00:00' WHERE uid=".$userId;
						$db->setQuery($query);
						$ret = $db->query();
						if (!$ret) {
                            $app->enqueueMessage(nl2br($db->getErrorMsg()),'error');
				            print "<p>Database Error</p>";
                            print "<p>Error:".$db->getErrorNum()."-".$db->getErrorMsg()."</p>";
						}
					} else {
				        $app->redirect("index.php?option=".$editProfileOption."&view=".$editProfileView."&layout=".$editProfileLayout,
					  			JText::_('PLG_SYSTEM_PASSWORDCONTROL_UPDATE_YOUR_PASSWORD'));
					}
			    }
            }

            else {
                // ndays is set so next change date should never be null.
                // On a fresh install however existing users will have the value of NULL.
                // It would also be NULL if the system admin had tried forcefirst before setting ndays
                // because then users would have already been created in the system.

                if ( $next_change == '0000-00-00 00:00:00') { return; }

                // Also have the situation where the user has optionally changed their password early without
			    // us prompting.  What do here is just update the next change time as appropriate.
			    // To detect this we need to check the next_change date to see if it is current.
			    // Might need to allow a few minutes either side here.  We will see.
			    if ($next_change > $ldate ) {
			       $chk_upd_date = strtotime(date("Y-m-d H:i:s", strtotime($ldate)) . " +".$ndays." days");
			       $chk_upd_date = date ( "Y-m-d H:i:s" , $chk_upd_date );
			       if ($chk_upd_date > $next_change ) {
  				      $next_change = $chk_upd_date;
				      $query = "UPDATE #__passwordcontrol SET next_password_change = '".$next_change."' WHERE uid=".$userId;
				      $db->setQuery($query);
				      $ret = $db->query();
				      if (!$ret) {
					     $app->enqueueMessage(nl2br($db->getErrorMsg()),'error');
					   	 print "<p>Database Error</p>";
					   	 print "<p>Error:".$db->getErrorNum()."-".$db->getErrorMsg()."</p>";
				      }
				   }
				}

                if ( $date < $next_change) { return; }    // Not time to change password yet.

                // The user has exceeded our set limit for password changing
			    if ($option == $editProfileOption && $task == $editProfileSaveTask)
			    {
			        // The user is saving their profile.  If they are changing the password then
			        // it will be captured in the onUserAfterSave function.
			        // So do not need to do anything here, but we will update the last visit date to a real value.
			        $user->setLastVisit();
			        $date = JFactory::getDate();
			        $user->lastvisitDate = $date->toMySQL();

				} else if ( $option == $editProfileOption && $view == $editProfileView && empty($layout))
				{
				    // Capture the profile save complete and redirect if required
				    if ($subredirect == 1) { $app->redirect("index.php"); }

			    } else if(!($option == $editProfileOption && $view == $editProfileView && $layout == $editProfileLayout))
			    {
			        // They may also come in here after the save when the password has not changed.

	                if ($ldate > $next_change ) {
			           // Update DB to not prompt until next required date
					   //$db	=& JFactory::getDBO();
					   //$sql = $db->getQuery(true);
					   //$date = JFactory::getDate();
					   $ndate = strtotime("+".$ndays." days");
                       $ndate = date ( "Y-m-d H:i:s" , $ndate );
					   $query = "UPDATE #__passwordcontrol SET next_password_change = '".$ndate."' WHERE uid=".$userId;
					   $db->setQuery($query);
					   $ret = $db->query();
					   if (!$ret) {
                          $app->enqueueMessage(nl2br($db->getErrorMsg()),'error');
				          print "<p>Database Error</p>";
                          print "<p>Error:".$db->getErrorNum()."-".$db->getErrorMsg()."</p>";
					   }
	               } else {
			          $app->redirect("index.php?option=".$editProfileOption."&view=".$editProfileView."&layout=".$editProfileLayout,
			       			JText::_('PLG_SYSTEM_PASSWORDCONTROL_UPDATE_YOUR_PASSWORD'));
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

	         if ( $ret ) {
	            return;  // Same password, they still have to change it.
	         }
	         else {
	            // This is a different password so update our table.
	            // There will be a small time difference but this should be negligible.
	            $passwd = $data[password];
	            $date = JFactory::getDate();
	            $retn = $this->update_passwordcontrol2($userId, $date, $passwd);

	            if (!$retn) { return false; }
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

    	// Extract the old salt that we used.
    	$salt = substr($cpass, -32);
    	$ocrypt = substr($cpass, 0, 32);
	    // $salt = JUserHelper::genRandomPassword(32);
	    $crypt = JUserHelper::getCryptedPassword($pass, $salt);
        // $password = $crypt . ':' . $salt;

        // If the same return true.
        if ($crypt == $ocrypt) { return true; }
           // Else return false.
        return false;
    }

    function update_passwordcontrol($uid, $udate)
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

    function update_passwordcontrol2($uid, $udate, $pass)
	{
	   $db	=& JFactory::getDBO();
	   $sql = $db->getQuery(true);
	   $query = "UPDATE #__passwordcontrol SET old_password = '".$pass."', last_password_change = '".$udate."' WHERE uid=".$uid;
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
