<?php
/**
 * @version     1.0
 * @package     com_podsso
 * @copyright  	Fanao
 * @license     GPLv2 or later
 * @author      Mehran Rahbardar
 */
 

// No direct access to this file
defined('_JEXEC') or die('Restricted access');

// import Joomla view library
jimport('joomla.application.component.view');

/**
 * Login View
 */
class PodSsoViewLogin extends JViewLegacy
{
	/**
	 * display method of Login view
	 * @return void
	 */
	public function display($tpl = null)
	{

		// Check for errors.
		if (count($errors = $this->get('Errors'))){
			JError::raiseError(500, implode('<br />', $errors));
			return false;
		};

		

		// Display the template
		parent::display($tpl);

	}

	
}
?>