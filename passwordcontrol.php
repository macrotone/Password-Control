<?php
/**
 * @version        passwordcontrol.php
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

        $date = JFactory::getDate();

        // get settings from passwordcontrol parameters
		$params 	= new JParameter(JPluginHelper::getPlugin('system', 'passwordcontrol')->params);

        // forcefirst 0 = no forcefirst, everything else means force password change on first logon
		if ( $params->get('forcefirst', '0') == 0) { $forcefirst = 0; }
		else { $forcefirst = 1; }

        // firstredirect 0 = no firstredirect, everything else means redirect
		if ( $params->get('firstredirect', '0') == 0) { $firstredirect = 0; }
		else { $firstredirect = 1; }

        $ndays = $params->get('ndays','0');

        // subredirect   0 = no redirect everything else means redirect
        if ( $params->get('subredirect', '0') == 0) { $subredirect = 0; }
		else { $subredirect	= 1; }

		if($forcefirst ==1 && $user->lastvisitDate == "0000-00-00 00:00:00")
		{
			// The user has never visited before
			if($option == $editProfileOption && $task == $editProfileSaveTask)
			{
				// The user is saving their profile
				// Set the last visit date to a real value to stop us continuing forcing them to change their password
				$user->setLastVisit();
			    $date = JFactory::getDate();
				$user->lastvisitDate = $date->toMySQL();

                // Redirect if configured.
                if ($firstredirect == 1) { $app->redirect("index.php"); }
			}
			else if(!($option == $editProfileOption && $view == $editProfileView && $layout == $editProfileLayout))
			{
				// The user is not on the edit profile form so redirect to edit profile
				$lang =  & JFactory::getLanguage();
				$lang->load('plg_system_passwordcontrol', JPATH_ADMINISTRATOR);

				$app->redirect(
					"index.php?option=".$editProfileOption."&view=".$editProfileView."&layout=".$editProfileLayout,
					JText::_('PLG_SYSTEM_PASSWORDCONTROL_UPDATE_YOUR_PASSWORD'));
			}
		}
		else if ($ndays > 0 && $user->lastvisitDate != "0000-00-00 00:00:00")
		{
            $ldate = $user->lastvisitDate;
            $datel = strtotime($ldate, 0);
            $daten = strtotime($date, 0);

            $diff = floor(($daten-$datel) / 86400);
            if ($diff < $ndays) { return; }

            // The user has exceeded our set limit for password changing
			if($option == $editProfileOption && $task == $editProfileSaveTask)
			{
				// The user is saving their profile.
				// Update the last visit date so we do not continue forcing them to change their password
				$user->setLastVisit();
			    $date = JFactory::getDate();
				$user->lastvisitDate = $date->toMySQL();

                // Redirect if appropriate
                if ($subredirect == 1) { $app->redirect("index.php"); }
			}
			else if(!($option == $editProfileOption && $view == $editProfileView && $layout == $editProfileLayout))
			{
				// The user is not on the edit profile form so redirect to edit profile
				$lang =  & JFactory::getLanguage();
				$lang->load('plg_system_passwordcontrol', JPATH_ADMINISTRATOR);

				// Redirect to the edit screen.
				$app->redirect(
					"index.php?option=".$editProfileOption."&view=".$editProfileView."&layout=".$editProfileLayout,
					JText::_('PLG_SYSTEM_PASSWORDCONTROL_UPDATE_YOUR_PASSWORD'));
			}
		}
	}
}
