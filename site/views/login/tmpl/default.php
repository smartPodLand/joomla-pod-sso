<?php
/**
 * @version     1.0
 * @package     com_podsso
 * @copyright   fanap.coms
 * @author     Mehran Rahbardar
 */
// No direct access to this file
defined('_JEXEC') or die('Restricted access');
jimport('joomla.application.application');
jimport('joomla.application.input.cookie');


$params = JComponentHelper::getParams('com_podsso');
$global_config = [
	"service"       => $params->get('platform_address'),
	"sso"           => $params->get('sso_address') . "/oauth2/",
	"client_id"     => $params->get('client_id'),
	"client_secret" => $params->get('client_secret'),
	"api_token"     => $params->get('api_token'),
	"guild"         => $params->get('guild_code')
];

function updateUserSession($username, $sessionId)
{
	try
	{
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);

		// Fields to update.
		$fields = array(
			$db->quoteName('username') . ' = ' . $db->quote($username),
			$db->quoteName('guest') . ' = 0',
		);

		$conditions = array(
			$db->quoteName('session_id') . ' = ' . $db->quote($sessionId)
		);

		$query->update($db->quoteName('#__session'))->set($fields)->where($conditions);

		$db->setQuery($query);

		$result = $db->execute();

	}
	catch (Exception $e)
	{
		die($e->getMessage());
	}
}

function getPodUserProfile($access_token, $config)
{
	$headers = array('_token_: ' . $access_token, '_token_issuer_: 1');
	$url     = $config['service'] . '/nzh/getUserProfile/';
	$ch      = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	$response = curl_exec($ch);
	curl_close($ch);

	return json_decode($response);
}

function getBusinessId($config)
{
	$curl = curl_init();
	curl_setopt_array($curl, [
		CURLOPT_URL            => $config['service'] . "/nzh/getUserBusiness",
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_MAXREDIRS      => 10,
		CURLOPT_TIMEOUT        => 30,
		CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST  => 'GET',
		CURLOPT_HTTPHEADER     => [
			"_token_: {$config['api_token']}",
			"_token_issuer_: 1"
		],
	]);
	curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
	$response = curl_exec($curl);
	$err      = curl_error($curl);

	curl_close($curl);
	if ($err)
	{
		echo $err;

		return false;
	}
	else
	{
		return json_decode($response)->result->id;
	}
}

function podLogin($myApp, $code, $config)
{
	try
	{
		$url     = $config['sso'] . '/token/';
		$ch      = curl_init($url);
		$session = JFactory::getSession();
		$fields  = "client_id={$config['client_id']}&client_secret={$config['client_secret']}&code={$_GET['code']}&redirect_uri={$session->get('redirect_uri')}&grant_type=authorization_code";
		curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
		$response = curl_exec($ch);
		$err      = curl_error($ch);
		if ($err)
		{
			echo $err;
			exit();
		}
		curl_close($ch);
		$token = json_decode($response);

		$mtemp['access_token'] = $token->access_token;

		$session->set('pod_access_token', $mtemp['access_token']);
		$mtemp['refresh_token'] = $token->refresh_token;
		$session->set('pod_refresh_token', $mtemp['refresh_token']);

		$session->set('pod_api_token', $config['api_token']);
		$session->set('pod_guild_code', $config['guild']);
		$session->set('pod_biz_id', getBusinessId($config));
		$result    = getPodUserProfile($mtemp['access_token'], $config);
		$errorCode = $result->errorCode;
		if ($errorCode == '0')
		{
			$session->set('pod_user_id', $result->result->userId);
			$firstName      = $result->result->firstName;
			$lastName       = $result->result->lastName;
			$username       = $result->result->username;
			$ssoId          = $result->result->ssoId;
			$podEmail       = $result->result->email;
			$mtemp['ssoId'] = $ssoId;

			try
			{
				$db    = JFactory::getDbo();
				$query = $db->getQuery(true);
				$query->select($db->quoteName(array('id', 'username', 'email')));
				$query->from($db->quoteName('#__users'));
				$query->where($db->quoteName('email') . '=' . $db->quote($podEmail));
				$db->setQuery($query);
				$results = $db->loadObjectList();
				if (!$results)
				{
					$fullName          = $firstName . " " . $lastName;
					$profile           = new stdClass();
					$profile->name     = $fullName;
					$profile->username = $username;
					$profile->email    = $podEmail;
					$profile->password = '$2y$10$mPWhl31p7hIAQU03R8S8V.HSE1J1Wu6T3y.MExuhO/DimF1ZXda1m';

					$result = JFactory::getDbo()->insertObject('#__users', $profile);
					if ($result)
					{
						$db    = JFactory::getDbo();
						$query = $db->getQuery(true);
						$query->select($db->quoteName(array('id')));
						$query->from($db->quoteName('#__users'));
						$query->where($db->quoteName('email') . '=' . $db->quote($podEmail));
						$db->setQuery($query);
						$result              = $db->loadObjectList();
						$UID                 = $result[0]->id;
						$userGroup           = new stdClass();
						$userGroup->user_id  = $UID;
						$userGroup->group_id = 2;
						$result              = JFactory::getDbo()->insertObject('#__user_usergroup_map', $userGroup);

					}
					else
					{
						return JText::_("COM_PODSSO_UNKNOWN_ERROR");
					}
				}
				else
				{
					$UID      = $results[0]->id;
					$username = $results[0]->username;
				}

				$user = JUser::getInstance($UID);

				$session->set('user', $user);

				$app = JFactory::getApplication('site');
				updateUserSession($username, $session->getId(), "POD");
				$user->setLastVisit();

				$app->redirect(JURI::root() . 'index.php?');
			}
			catch (Exception $e)
			{
				return ($e->getMessage());
			}

			return '';
		}
		else
		{
			switch ($errorCode)
			{
				case '21':
					return JText::_("COM_PODSSO_INVALID_TOKEN");
				default  :
					return JText::_("COM_PODSSO_UNKNOWN_ERROR");
			}
		}
	}
	catch (Exception $e)
	{
		die('execption occured');
		die($e->getMessage());
	}
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST')
{
	if (version_compare(PHP_VERSION, '5.3.1', '<'))
	{
		die('Your host needs to use PHP 5.3.1 or higher to run this version of Joomla!');
	}

	if (file_exists(__DIR__ . '/defines.php'))
	{
		include_once __DIR__ . '/defines.php';
	}

	if (!defined('_JDEFINES'))
	{
		define('JPATH_BASE', __DIR__);
		require_once JPATH_BASE . '/includes/defines.php';
	}

	require_once JPATH_BASE . '/includes/framework.php';
	$app = JFactory::getApplication('site');

	if (isset($_POST['socialLogin']) && $_POST['socialLogin'] != '')
	{
		$socialLogin = $_POST['socialLogin'];
		if ($socialLogin == 'google')
		{
			$message = googleLogin($app);
		}
		else if ($socialLogin == 'pod')
		{
			$message = podLogin($app, $global_config);
		}
	}
	else
	{
		$username = $_POST['username'];
		$password = $_POST['password'];

		if ($username == '' or $password == '')
		{
			$message = JText::_("COM_PODSSO_INVALID_USERNAME_OR_PASSWORD");
		}
		else
		{
			try
			{
				$db    = JFactory::getDbo();
				$query = "SELECT U.id, U.`password`, U.`username` FROM tasg5_users U WHERE U.username = '" . $username . "' OR U.email = '" . $username . "'";
				$db->setQuery($query);
				$result = $db->loadObject();
				if ($result)
				{
					$match = JUserHelper::verifyPassword($password, $result->password, $result->id);
					if ($match)
					{
						$user    = JUser::getInstance($result->id);
						$session = JFactory::getSession();
						$session->set('user', $user);
						$user->setLastVisit();
						updateUserSession($result->id, $result->username, $session->getId(), "Joomla", $myApp);
						$app->redirect(JURI::root() . 'index.php?');
					}
					else
					{
						$message = JText::_("COM_PODSSO_INVALID_USERNAME_OR_PASSWORD");
					}
				}
				else
				{
					$message = JText::_("COM_PODSSO_INVALID_USERNAME_OR_PASSWORD");
				}
			}
			catch (Exception $e)
			{
				$message = $e->getMessage();
			}
		}
	}
}
else if ($_SERVER['REQUEST_METHOD'] == 'GET')
{

	$app    = JFactory::getApplication('site');
	$config = $global_config;
	if (isset($_GET['code']))
	{

		podLogin($_GET['code'], $app, $global_config);
	}
	else
	{
		$uri          = &JFactory::getURI();
		$redirect_uri = $uri->toString();
		$session      = JFactory::getSession();
		$session->set('redirect_uri', $redirect_uri);

		header("Location: {$config['sso']}authorize/?client_id={$config['client_id']}&response_type=code&redirect_uri={$redirect_uri}&scope=profile email");
	}
}
