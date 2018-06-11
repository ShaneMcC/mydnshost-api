<?php
	use shanemcc\phpdb\DB;

	// We only output json.
	header('Content-Type: application/json');

	require_once(dirname(__FILE__) . '/functions.php');
	require_once(dirname(__FILE__) . '/response.php');

	$router = new MethodRouter();

	foreach (recursiveFindFiles(__DIR__ . '/methods') as $file) { include_once($file); }

	// Set the session handler.
	checkSessionHandler();

	// Initial response object.
	$resp = new api_response();

	// Figure out the method requested
	//
	// This will find the method path relative to where we are, this lets us
	// run in subdomains or at the root of the domain.
	// Firstly find the current path.
	$path = dirname($_SERVER['SCRIPT_FILENAME']);
	$path = preg_replace('#^' . preg_quote($_SERVER['DOCUMENT_ROOT']) . '#', '/', $path);
	$path = preg_replace('#^/+#', '/', $path);
	// Then remove that from the request URI to get the relative path requested
	$method = preg_replace('#^' . preg_quote($path . '/') . '#', '', $_SERVER['REQUEST_URI']);
	// Remove any query strings.
	$method = preg_replace('#\?.*$#', '', $method);
	// We have our method!
	$resp->method($method);

	// Request Method
	$requestMethod = $_SERVER['REQUEST_METHOD'];
	// Allow request method hacks for things that can't do it right.
	if (isset($_SERVER['HTTP_X_REQUEST_METHOD'])) {
		$requestMethod = $_SERVER['HTTP_X_REQUEST_METHOD'];
	}
	// Treat PUT and POST the same.
	if ($requestMethod == "PUT") { $requestMethod = "POST"; }

	// If we have POST/PUT data, retrieve it.
	$postdata = file_get_contents("php://input");
	// Allow passing a ?data= GET/POST parameter for compatability.
	if (empty($postdata) && isset($_REQUEST['data'])) {
		$postdata = $_REQUEST['data'];
	}

	// Now decode the postdata...
	if (!empty($postdata)) {
		$postdata = @json_decode($postdata, TRUE);
		if ($postdata == null) {
			$resp->sendError('Error with input.');
		}
	} else {
		$postdata = array();
	}

	// Get request ID
	if (array_key_exists('reqid', $postdata)) {
		$resp->reqid($postdata['reqid']);
	} else if (isset($_SERVER['HTTP_X_REQUEST_ID'])) {
		$postdata['reqid'] = $_SERVER['HTTP_X_REQUEST_ID'];
		$resp->reqid($postdata['reqid']);
	}

	// Look for impersonation header.
	if (isset($_SERVER['HTTP_X_IMPERSONATE'])) {
		$postdata['impersonate'] = ['email', $_SERVER['HTTP_X_IMPERSONATE']];
	} else if (isset($_SERVER['HTTP_X_IMPERSONATE_ID'])) {
		$postdata['impersonate'] = ['id', $_SERVER['HTTP_X_IMPERSONATE_ID']];
	}

	// Set the execution context, used by API Methods.
	$context = ['response' => $resp,
	            'data' => $postdata,
	            'db' => DB::get(),
	           ];

	// Look for authentication.
	// This can either be a session ID from a previous login, or for new logins
	// this can be a USER/PASSWORD Basic auth, or an API Key.
	//
	// Priority:
	//   - Session
	//   - API Keys
	//   - Basic Auth
	//
	// If you attempt to use multiple, then we only try the first one.
	$user = FALSE;

	/**
	 * What access permissions does this account have?
	 * If an API Key is provided, a permission is only granted if both the
	 * user and the key allow it.
	 * (This only applies in cases where a permission can be set on a key)
	 *
	 * @param $user User to get permissions for.
	 * @param $key (Optional) API Key to limit permissions by.
	 * @return Array of permissions.
	 */
	function getAccessPermissions($user, $key = NULL) {
		$access = ['domains_read' => ($key == null) ? true : (true && $key->getDomainRead()),
		           'domains_write' => ($key == null) ? true : (true && $key->getDomainWrite()),
		           'user_read' => ($key == null) ? true : (true && $key->getUserRead()),
		           'user_write' => ($key == null) ? true : (true && $key->getUserWrite()),
		          ];

		foreach ($user->getPermissions() as $permission => $value) {
			if ($value) {
				$access[$permission] = true;
			}
		}

		// Disable all permissions if we have not accepted the minimum terms
		// version required to use the API except user_read and user_write.
		//
		// This will allow the user to still accept the new terms, or update
		// their user info/delete their account etc, but won't allow them to do
		// other useful things.
		if ($user->getAcceptTerms() < getSystemAPIMinimumTermsTime()) {
			foreach ($access as $permission => &$value) {
				if (!in_array($permission, ['user_read', 'user_write'])) {
					$value = false;
				}
			}
		}

		return $access;
	}

	function getKnownDevice($user, &$context) {
		if ($user != false && isset($_SERVER['HTTP_X_2FA_DEVICE_ID'])) {
			$device = TwoFactorDevice::loadFromUserDeviceID($context['db'], $user->getID(), $_SERVER['HTTP_X_2FA_DEVICE_ID']);
			if ($device !== FALSE) {
				if ($device->getCreated() > time() - (60 * 60 * 24 * 30)) {
					$context['device'] = $device;
					$device->setLastUsed(time())->save();
				} else {
					$device->delete();
				}
			}
		}
	}

	$errorExtraData = [];

	if (isset($_SERVER['HTTP_X_SESSION_ID'])) {
		session_id($_SERVER['HTTP_X_SESSION_ID']);
		session_start(['use_cookies' => '0', 'cache_limiter' => '']);

		if (isset($_SESSION['userid']) && isset($_SESSION['access'])) {
			$user = User::load($context['db'], $_SESSION['userid']);
			$key = FALSE;
			if (isset($_SESSION['keyid'])) {
				$key = APIKey::load($context['db'], $_SESSION['keyid']);
				if ($key == FALSE) {
					$key->setLastUsed(time())->save();
				} else {
					// Key no longer exists, so session is no longer valid.
					$user = FALSE;
				}
			}

			if ($user !== FALSE) {
				getKnownDevice($user, $context);

				$context['sessionid'] = $_SERVER['HTTP_X_SESSION_ID'];
				$context['user'] = $user;
				$context['access'] = getAccessPermissions($user, ($key == false ? null : $key));
			}
		} else if (isset($_SESSION['domainkey']) && isset($_SESSION['access'])) {
			$key = DomainKey::load($context['db'], $_SESSION['domainkey']);

			if ($key != FALSE) {
				$user = $key->getDomainKeyUser();

				$context['sessionid'] = $_SERVER['HTTP_X_SESSION_ID'];
				$context['user'] = $user;
				$context['access'] = ['domains_read' => true, 'domains_write' => (true && $key->getDomainWrite())];
				$context['domainkey'] = $key;
				$key->setLastUsed(time())->save();
			}
		}

		session_commit();
	} else if (isset($_SERVER['HTTP_X_API_USER']) && isset($_SERVER['HTTP_X_API_KEY'])) {
		$user = User::loadFromEmail($context['db'], $_SERVER['HTTP_X_API_USER']);
		if ($user != FALSE) {
			$key = APIKey::loadFromUserKey($context['db'], $user->getID(), $_SERVER['HTTP_X_API_KEY']);

			if ($key != FALSE) {
				$context['user'] = $user;
				$context['access'] = getAccessPermissions($user, $key);
				$context['key'] = $key;
				$key->setLastUsed(time())->save();
			} else {
				// Invalid Key, reset user.
				$user = FALSE;
			}
		}
	} else if (isset($_SERVER['HTTP_X_DOMAIN']) && isset($_SERVER['HTTP_X_DOMAIN_KEY'])) {
		$domain = Domain::loadFromDomain($context['db'], $_SERVER['HTTP_X_DOMAIN']);
		if ($domain != FALSE) {
			$key = DomainKey::loadFromDomainKey($context['db'], $domain->getID(), $_SERVER['HTTP_X_DOMAIN_KEY']);

			if ($key != FALSE) {
				$user = $key->getDomainKeyUser();

				$context['user'] = $user;
				$context['access'] = ['domains_read' => true, 'domains_write' => (true && $key->getDomainWrite())];
				$context['domainkey'] = $key;
				$key->setLastUsed(time())->save();
			}
		}
	} else if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
		$user = User::loadFromEmail($context['db'], $_SERVER['PHP_AUTH_USER']);

		if ($user !== FALSE && $user->checkPassword($_SERVER['PHP_AUTH_PW'])) {
			$keys = TwoFactorKey::getSearch($context['db'])->where('user_id', $user->getID())->where('active', 'true')->find('key');

			// Don't check 2FA keys if we have a valid saved device.
			getKnownDevice($user, $context);
			if (isset($context['device'])) {
				$keys = [];
			}

			$valid = true;
			if (count($keys) > 0) {
				$valid = false;
				$testCode = isset($_SERVER['HTTP_X_2FA_KEY']) ? $_SERVER['HTTP_X_2FA_KEY'] : NULL;

				$ga = new PHPGangsta_GoogleAuthenticator();

				if ($testCode !== NULL) {
					foreach ($keys as $key) {
						if ($key->verify($testCode, 1)) {
							$valid = true;
							$key->setLastUsed(time())->save();

							if (isset($_SERVER['HTTP_X_2FA_SAVE_DEVICE']) || isset($_SERVER['HTTP_X_2FA_DEVICE_ID'])) {
								$device = (new TwoFactorDevice($context['db']))->setUserID($user->getID())->setCreated(time())->setLastUsed(time());

								if (isset($_SERVER['HTTP_X_2FA_DEVICE_ID'])) {
									$device->setDeviceID($_SERVER['HTTP_X_2FA_DEVICE_ID']);
									$resp->setHeader('device_id', $_SERVER['HTTP_X_2FA_DEVICE_ID']);
								} else {
									$device->setDeviceID(TRUE);
									$resp->setHeader('device_id', $device->getDeviceID());
								}

								if (isset($_SERVER['HTTP_X_2FA_SAVE_DEVICE']) && !empty($_SERVER['HTTP_X_2FA_SAVE_DEVICE']) && $_SERVER['HTTP_X_2FA_SAVE_DEVICE'] != '.') {
									$device->setDescription($_SERVER['HTTP_X_2FA_SAVE_DEVICE']);
								} else {
									$device->setDescription('Device ID: ' . $device->getDeviceID());
								}
								$resp->setHeader('device_name', $device->getDescription());
								try {
									$device->validate();
									$device->save();

									$context['device'] = $device;
								} catch (Execption $ex) {
									$resp->removeHeader('device_name');
									$resp->removeHeader('device_id');
								}
							}
							break;
						}
					}
					$errorExtraData = '2FA key invalid.';
					$resp->setHeader('login_error', '2fa_invalid');
				} else {
					$errorExtraData = '2FA key required.';
					$resp->setHeader('login_error', '2fa_required');
				}
			}

			if ($valid) {
				$resp->removeHeader('login_error');
				$context['user'] = $user;
				$context['access'] = getAccessPermissions($user);
			} else {
				$user = FALSE;
			}
		} else {
			// Failed password check, reset user.
			$user = FALSE;
		}
	}

	// Is this account disabled?
	if ($user != FALSE && $user->isDisabled()) {
		$reason = $user->getDisabledReason();
		$user = FALSE;
		unset($context['user']);
		unset($context['access']);

		// If a reason has been specified, show it, otherwise we treat the
		// request as unauthenticated.
		if (!empty($reason)) {
			$resp->setErrorCode('403', 'Forbidden');
			$resp->sendError('Access denied.', 'Account has been suspended: ' . $reason);
		}
	}

	// Handle impersonation.
	if ($user != FALSE && array_key_exists('user', $context) && isset($postdata['impersonate'])) {
		if (isset($context['access']['impersonate_users']) && parseBool($context['access']['impersonate_users'])) {
			if ($postdata['impersonate'][0] == 'id') {
				$impersonating = User::load($context['db'], $postdata['impersonate'][1]);
			} else if ($postdata['impersonate'][0] == 'email') {
				$impersonating = User::loadFromEmail($context['db'], $postdata['impersonate'][1]);
			} else {
				$impersonating = false;
			}

			if ($impersonating !== FALSE) {
				// All the API Methods only look for user, so change it.
				$context['user'] = $impersonating;
				$context['impersonator'] = $user;

				// Reset access to that of the user.
				$context['access'] = getAccessPermissions($impersonating);

				// Add some extra responses so that it's obvious what is happening.
				$resp->setHeader('impersonator', $user->getEmail());
				$resp->setHeader('impersonating', $impersonating->getEmail());
			} else {
				$resp->sendError('No such user to impersonate.');
			}
		} else {
			// Only admins can impersonate.
			$resp->setErrorCode('403', 'Forbidden');
			$resp->sendError('Access denied.');
		}
	}

	// Now, look for the API Method that does what we want!
	try {
		$router->run($requestMethod, $method, $context);
		$resp->send();
	} catch (RouterMethod_NotAllowed $ex) {
		$resp->setErrorCode('405', 'Method Not Allowed');
		$resp->sendError('Unsupported request method (' . $requestMethod . ').');
	} catch (RouterMethod_NotFound $ex) {
		$resp->setErrorCode('404', 'Not Found');
		$resp->sendError('Unknown method requested (' . $method . ').');
	} catch (RouterMethod_NeedsAuthentication $ex) {
		if (!empty($ex->getMessage())) { $errorExtraData[] = $ex->getMessage(); }
		header('WWW-Authenticate: Basic realm="API"');
		$resp->setErrorCode('401', 'Unauthorized');
		$resp->sendError('Authentication required.', $errorExtraData);
	} catch (RouterMethod_AccessDenied $ex) {
		if (!empty($ex->getMessage())) { $errorExtraData[] = $ex->getMessage(); }
		$resp->setErrorCode('403', 'Forbidden');
		$resp->sendError('Access denied.', $errorExtraData);
	} catch (RouterMethod_PermissionDenied $ex) {
		$resp->setErrorCode('403', 'Forbidden');
		$resp->sendError('Permission Denied', 'You do not have the required permission: ' . $ex->getMessage());
	} catch (Exception $ex) {
		$resp->setErrorCode('500', 'Internal Server Error');
		$resp->sendError('Internal Server Error.', $ex->getMessage());
	}

	// Shouldn't get here, but exit anyway just in case.
	$resp->sendError('Unknown error.');
