<?php
// No direct access to this file
defined('_JEXEC') or die('Restricted access');

class plgsystempasswordcontrolInstallerScript
{
   /*
    * The release value would ideally be extracted from <version> in the manifest file,
    * but at preflight, the manifest file exists only in the uploaded temp folder.
    */
   private $release = '0.0.4';

   /*
    * $parent is the class calling this method.
    * $type is the type of change (install, update or discover_install, not uninstall).
    * preflight runs before anything else and while the extracted files are in the uploaded temp folder.
    * If preflight returns false, Joomla will abort the update and undo everything already done.
    */
   function preflight( $type, $parent ) {
      // this component does not work with Joomla releases prior to 1.6
      // abort if the current Joomla release is older
      $jversion = new JVersion();
      if( version_compare( $jversion->getShortVersion(), '1.6', 'lt' ) ) {
         Jerror::raiseWarning(null, 'Cannot install PLG_SYSTEM_PASSWORDCONTROL in a Joomla release prior to 1.6');
         return false;
      }

        if( version_compare( $jversion->getShortVersion(), '1.6', 'eq' ) ) {
         Jerror::raiseWarning(null, 'Although not tested on 1.6 it is expected to run.  Use at your own risk.');
         // return false;
      }

      // abort if the release being installed is not newer than the currently installed version
      if ( $type == 'update' ) {
         $oldRelease = $this->getParam('version');
         $rel = $oldRelease . ' to ' . $this->release;
         if ( version_compare( $this->release, $oldRelease, 'le' ) ) {
            Jerror::raiseWarning(null, 'Incorrect version sequence. Cannot upgrade ' . $rel);
            return false;
         }
      }
      else { $rel = $this->release; }

      echo '<p>' . JText::_('PLG_SYSTEM_PASSWORDCONTROL_PREFLIGHT_' . $type . '_TEXT').  ' ' . $rel . '</p>';
   }

   /*
    * $parent is the class calling this method.
    * install runs after the database scripts are executed.
    * If the extension is new, the install method is run.
    * If install returns false, Joomla will abort the install and undo everything already done.
    */
   function install( $parent ) {
      echo '<p>' . JText::_('PLG_SYSTEM_PASSWORDCONTROL_INSTALL_TEXT') . ' to ' . $this->release . '</p>';
      $this->createDBprocs();
      // You can have the backend jump directly to the newly installed component configuration page
      // $parent->getParent()->setRedirectURL('index.php?option=com_passwordcontrol');
   }

   /*
    * $parent is the class calling this method.
    * update runs after the database scripts are executed.
    * If the extension exists, then the update method is run.
    * If this returns false, Joomla will abort the update and undo everything already done.
    */
   function update( $parent ) {
      echo '<p>' . JText::_('PLG_SYSTEM_PASSWORDCONTROL_UPDATE_TEXT') . ' to ' . $this->release . '</p>';
        $this->createDBprocs();
   }

   /*
    * $parent is the class calling this method.
    * $type is the type of change (install, update or discover_install, not uninstall).
    * postflight is run after the extension is registered in the database.
    */
   function postflight( $type, $parent ) {
      // set initial values for component parameters
      $params['my_param0'] = 'Plugin_version ' . $this->release;
      $this->setParams( $params );

      echo '<p>' . JText::_('PLG_SYSTEM_PASSWORDCONTROL_POSTFLIGHT_' . $type . '_TEXT') . ' to ' . $this->release. '</p>';
   }

   /*
    * $parent is the class calling this method
    * uninstall runs before any other action is taken (file removal or database processing).
    */
   function uninstall( $parent ) {
      echo '<p>' . JText::_('PLG_SYSTEM_PASSWORDCONTROL_UNINSTALL_TEXT') . $this->release . '</p>';

      $db = JFactory::getDbo();

      $query="DROP FUNCTION IF EXISTS `#__oldpasswordcheck`";
        $db->setQuery($query);
      $db->query();

      $query="DROP PROCEDURE IF EXISTS `#__updcontroltable`;";
      $db->setQuery($query);
      $db->query();

   }

   /*
    * get a variable from the manifest file (actually, from the manifest cache).
    */
   function getParam( $name ) {
      $db = JFactory::getDbo();
      $db->setQuery('SELECT manifest_cache FROM #__extensions WHERE name = "plg_system_passwordcontrol"');
      $manifest = json_decode( $db->loadResult(), true );
      return $manifest[ $name ];
   }

   /*
    * sets parameter values in the component's row of the extension table
    */
   function setParams($param_array) {
      if ( count($param_array) > 0 ) {
         // read the existing component value(s)
         $db = JFactory::getDbo();
         $db->setQuery('SELECT params FROM #__extensions WHERE name = "plg_system_passwordcontrol"');
         $params = json_decode( $db->loadResult(), true );
         // add the new variable(s) to the existing one(s)
         foreach ( $param_array as $name => $value ) {
            $params[ (string) $name ] = (string) $value;
         }
         // store the combined new and existing values back as a JSON string
         $paramsString = json_encode( $params );
         $db->setQuery('UPDATE #__extensions SET params = ' .
            $db->quote( $paramsString ) .
            ' WHERE name = "plg_system_passwordcontrol"' );
            $db->query();
      }
   }

   function createDBprocs()
   {
      $db = JFactory::getDbo();
      $db->setQuery('UPDATE #__passwordcontrol_meta SET version = "'. $this->release . '", type ="plugin" ');
      $db->query();

      /*
        * Create database procedures in here since we cannot create them in the install script.
        */

      $query="DROP PROCEDURE IF EXISTS `#__updcontroltable`";
      $db->setQuery($query);
      $db->query();

      $query="CREATE PROCEDURE `#__updcontroltable`(";
      $query.= "\n   IN user_id INT(11),";
      $query.= "\n   IN last_password_change_in DATETIME,";
      $query.= "\n   IN next_password_change_in DATETIME,";
      $query.= "\n   IN new_password_in VARCHAR(100),";
      $query.= "\n   IN max_seq INT(10)";
      $query.= "\n)";
      $query.= "\nBEGIN";
      $query.= "\n   -- Declare variable for the old id";
      $query.= "\n   DECLARE old_id INT(10);";
      $query.= "\n ";
      $query.= "\n  SET max_seq = IFNULL(max_seq, 1);";
      $query.= "\n  SET max_seq = IF(max_seq<1, 1, max_seq);";
      $query.= "\n  ";
      $query.= "\n   SELECT (`seq_id`+1) % max_seq as next_sequence_id";
      $query.= "\n   FROM `#__passwordcontrol`";
      $query.= "\n   WHERE uid=user_id";
      $query.= "\n   ORDER BY last_password_change DESC";
      $query.= "\n       LIMIT 1";
      $query.= "\n        INTO old_id;";
      $query.= "\n ";
      $query.= "\n  -- If there is no old entry this is a new user being added";
      $query.= "\n  SET old_id = IFNULL(old_id,0);";
      $query.= "\n ";
      $query.= "\n  -- If there is no old entry do an insert otherwise update the entry by old_id";
      $query.= "\n  INSERT INTO `#__passwordcontrol`";
      $query.= "\n    (uid, seq_id, last_password_change, next_password_change, old_password)";
      $query.= "\n   VALUES (user_id, old_id, last_password_change_in, next_password_change_in, new_password_in)";
      $query.= "\n  ON DUPLICATE KEY UPDATE";
      $query.= "\n   last_password_change = last_password_change_in,";
      $query.= "\n   next_password_change = next_password_change_in,";
      $query.= "\n   old_password = new_password_in;";
      $query.= "\nEND";
      $db->setQuery($query);
      $db->query();

      $query="DROP FUNCTION IF EXISTS `#__oldpasswordcheck`";
      $db->setQuery($query);
      $db->query();

      $query="CREATE FUNCTION `#__oldpasswordcheck`(user_id int, new_passwd varchar(100)) RETURNS BOOLEAN";
      $query.= "\nREADS SQL DATA";
      $query.= "\nCOMMENT 'Check input password against stored values'";
      $query.= "\nBEGIN";
      $query.= "\n";
      $query.= "\n  DECLARE jos_salt varchar(75);";
      $query.= "\n  DECLARE jos_strpos INT;";
      $query.= "\n  DECLARE jos_passwd varchar(100);";
      $query.= "\n  DECLARE v_notfound BOOL default FALSE;";
      $query.= "\n  DECLARE v_match BOOL default FALSE;";
      $query.= "\n  DECLARE v_passwd varchar(100);";
      $query.= "\n  DECLARE new_pwd varchar(100);";
      $query.= "\n";
      $query.= "\n DECLARE pwd_cur";
      $query.= "\n   CURSOR FOR";
      $query.= "\n     SELECT old_password";
      $query.= "\n     FROM `#__passwordcontrol`";
      $query.= "\n     WHERE uid = user_id";
      $query.= "\n     ORDER by seq_id;";
      $query.= "\n";
      $query.= "\n  DECLARE continue handler";
      $query.= "\n    FOR NOT FOUND";
      $query.= "\n    SET v_notfound := TRUE;";
      $query.= "\n";
      $query.= "\n  declare exit handler";
      $query.= "\n    for sqlexception";
      $query.= "\n    close pwd_cur;";
      $query.= "\n";
      $query.= "\n  SET v_match := FALSE;";
      $query.= "\n";
      $query.= "\n  -- Need to extract the salt from each of the saved passwords";
      $query.= "\n  -- and check them against the new password encrypted with that salt.";
      $query.= "\n  -- If they match return false otherwise return true.  i.e.  Match not found.";
      $query.= "\n";
      $query.= "\n  open pwd_cur; ";
      $query.= "\n  pwd_loop: loop";
      $query.= "\n    fetch pwd_cur into v_passwd;";
      $query.= "\n";
      $query.= "\n    if v_notfound then";
      $query.= "\n      leave pwd_loop;";
      $query.= "\n    end if;";
      $query.= "\n";
      $query.= "\n    -- Handle encryption";
      $query.= "\n    SET jos_strPos = LOCATE(':',v_passwd);";
      $query.= "\n    SET jos_salt = SUBSTRING(v_passwd, jos_strPos+1);";
      $query.= "\n    SET jos_passwd = SUBSTR(v_passwd, 1, jos_strPos-1);";
      $query.= "\n    SET new_pwd = md5(concat(new_passwd,jos_salt));";
      $query.= "\n";
      $query.= "\n    IF new_pwd = jos_passwd THEN";
      $query.= "\n       SET v_match := TRUE;";
      $query.= "\n       leave pwd_loop;";
      $query.= "\n    END IF;";
      $query.= "\n";
      $query.= "\n  end loop;";
      $query.= "\n  close pwd_cur;";
      $query.= "\n  return v_match;";
      $query.= "\nEND";
      $db->setQuery($query);
      $ret=$db->query();

   }
}
?>