<?php
/**
 *
 * @package titania
 * @version $Id$
 * @copyright (c) 2008 phpBB Customisation Database Team
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
 *
 */

/**
* @ignore
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

/**
* mods_details
* Class for Details module
* @package details
*/
class mods_details extends titania_object
{
	public $p_master;
	public $u_action;

	/**
	 * Constructor
	 */
	public function __construct(&$p_master)
	{
		global $user;

		$this->p_master = &$p_master;

		$this->page = $user->page['script_path'] . $user->page['page_name'];
	}

	/**
	 * main method for this module
	 *
	 * @param string $id
	 * @param string $mode
	 */
	public function main($id, $mode)
	{
		global $user, $template, $cache;

		$user->add_lang(array('titania_details'));

		$submit		= isset($_POST['submit']) ? true : false;

		switch ($mode)
		{
			case 'screenshots':
			break;

			case 'preview':
			break;

			case 'changes':
			break;

			case 'email':
			break;

			case 'details':
			default:

			break;
		}
	}
}