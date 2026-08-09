<?php
/**
 *
 * Feed post bot. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace ger\feedpostbot;

class ext extends \phpbb\extension\base
{
	public function is_enableable()
	{
		$user = $this->container->get('user');
		$user->add_lang_ext('ger/feedpostbot', 'info_acp_feedpostbot');

		if (!function_exists('simplexml_load_string'))
		{
			trigger_error($user->lang('FPB_REQUIRE_SIMPLEXML'), E_USER_WARNING);
			return false;
		}

		if (!ini_get('allow_url_fopen') && !function_exists('curl_init'))
		{
			trigger_error($user->lang('FPB_REQUIRE_URL_FOPEN'), E_USER_WARNING);
			return false;
		}

		return true;
	}
}
