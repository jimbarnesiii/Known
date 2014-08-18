<?php

    /**
     * Hubs are central, or semi-central servers, that provide functionality for groups of Known users.
     * Functionality may include managed cron jobs, feed parsing, social syndication, discovery, and more.
     *
     */

    namespace Idno\Core {

        use Idno\Entities\User;

        class Hub extends \Idno\Common\Component
        {

            public $server = '';
            public $auth_token = '';
            public $secret = '';
            public $token = '';
            public $client = false;

            function __construct($server)
            {
                parent::__construct();
                $this->setServer($server);
            }

            function registerPages()
            {
                // These pages will be called by the hub after registration
                site()->addPageHandler('hub/register/site', 'Idno\Pages\Hub\Register\Site', true);
                site()->addPageHandler('hub/register/user', 'Idno\Pages\Hub\Register\User', true);
            }

            /**
             * Sets the hub server to connect to
             * @param $server
             */
            function setServer($server)
            {
                $this->server = $server;
            }

            /**
             * Sets the public auth token to use to communicate with the hub server
             * @param $token
             */
            function setAuthToken($token)
            {
                $this->auth_token = $token;
            }

            /**
             * Sets the secret auth token to use to communicate with the hub server
             * @param $secret
             */
            function setSecret($secret)
            {
                $this->secret = $secret;
            }

            /**
             * Establish a connection to the server at the specified location.
             *
             * @return bool True on success
             */
            function connect()
            {
                if ($details = $this->loadDetails()) {
                    // Apply pre-stored auth details and connect to server
                } else {
                    // Establish auth details, save them, and then connect
                    if ($details = $this->register()) {
                    }

                }

                // If we have details, and we're logged in, connect with OAuth
                if (site()->session()->isLoggedOn()) {
                    if (!empty($details)) {
                        try {
                            if (!$this->userIsRegistered()) {
                                $this->registerUser();
                            }
                        } catch (\Exception $e) {
                            error_log($e->getMessage());
                        }
                    }
                }

                return false;
            }

            /**
             * Retrieves a token for use in registering this Known site with a hub. Tokens last for 10 minutes.
             * @return string
             */
            function getRegistrationToken()
            {
                if (empty(site()->config->hub_settings)) {
                    site()->config->hub_settings = [];
                }
                if (!empty(site()->config->hub_settings['registration_token'])) {
                    if (!empty(site()->config->hub_settings['registration_token_expiry'])) {
                        if (site()->config->hub_settings['registration_token_expiry'] > (time() - 600)) {
                            return site()->config->hub_settings['registration_token'];
                        }
                    }
                }
                $token_generator                                   = new \OAuthProvider([]);
                $token                                             = $token_generator->generateToken(32);
                $config                                            = site()->config;
                $config->hub_settings['registration_token']        = bin2hex($token);
                $config->hub_settings['registration_token_expiry'] = time();
                $config->save();
                site()->config = $config;

                return site()->config->hub_settings['registration_token'];
            }

            /**
             * Register this Known site with the Known hub
             *
             * @return bool
             */
            function register()
            {
                $web_client = new Webservice();
                $results    = $web_client->post($this->server . 'hub/site/register', [
                    'url'   => site()->config()->getURL(),
                    'title' => site()->config()->getTitle(),
                    'token' => $this->getRegistrationToken()
                ]);

                if ($results['response'] == 200) {
                    return true;
                }

                return false;
            }

            /**
             * Register the current user with the Known hub. The site must have been registered first.
             *
             * @param bool $user
             * @return bool
             */
            function registerUser($user = false)
            {
                if (empty($user)) {
                    $user = site()->session()->currentUser();
                }
                if ($user instanceof User) {
                    $web_client = new Webservice();
                    $contents   = json_encode($user);
                    $time       = time();
                    $details    = $this->loadDetails();
                    $results    = $web_client->post($this->server . 'hub/user/register', [
                        'contents'   => $contents,
                        'time'       => $time,
                        'auth_token' => $details['auth_token'],
                        'signature'  => hash_hmac('sha1', $contents . $time . $details['auth_token'], $details['secret'])
                    ]);

                    return true;
                }

                return false;
            }

            /**
             * Makes a call to the hub
             *
             * @param $endpoint
             * @param $contents
             * @param bool $user
             * @return array|bool
             */
            function makeCall($endpoint, $contents, $user = false) {

                if (!$user) {
                    $user = site()->session()->currentUser();
                }

                if ($user instanceof User) {
                    if ($this->userIsRegistered($user)) {
                        $web_client = new Webservice();
                        $contents   = json_encode($contents);
                        $time       = time();
                        $details    = $user->hub_settings;
                        $results    = $web_client->post($this->server . $endpoint, [
                            'contents'   => $contents,
                            'time'       => $time,
                            'auth_token' => $details['token'],
                            'signature'  => hash_hmac('sha1', $contents . $time . $details['token'], $details['secret'])
                        ]);

                        return $results;
                    }
                }

                return false;

            }

            /**
             * Detect whether the current user has registered with the hub & stored credentials
             * @param bool $user
             * @return bool
             */
            function userIsRegistered($user = false)
            {
                if (empty($user)) {
                    $user = site()->session()->currentUser();
                    site()->session()->refreshSessionUser($user);
                }
                if ($user instanceof User) {
                    if (!empty($user->hub_settings)) {
                        if (!empty($user->hub_settings['token']) && !empty($user->hub_settings['secret'])) {
                            return true;
                        }
                    }
                }
                return false;
            }

            /**
             * Retrieves a link that will allow the current user to log into the hub page at $endpoint
             *
             * @param $endpoint
             * @param $callback
             * @return bool|string
             */
            function getRemoteLink($endpoint, $callback)
            {
                $user = site()->session()->currentUser();

                if ($this->userIsRegistered($user)) {
                    $results = $this->makeCall('hub/user/link', ['user' => $user->getUUID(), 'endpoint' => $endpoint, 'callback' => $callback]);
                    if (!empty($results['content']['link_token'])) {
                        $link_token = $results['content']['link_token'];
                        $time = time();
                        $signature = hash_hmac('sha1',$link_token . $time, $user->hub_settings['secret']);
                        return $this->server . $endpoint . '?token=' . urlencode($link_token) . '&time=' . $time . '&signature=' . $signature;
                    }
                }

                return false;

            }

            /**
             * Load the locally stored auth token & secret details, or register with the hub if no details have been
             * saved
             * @return bool
             */
            function loadDetails()
            {
                if (!empty(site()->config->hub_settings['auth_token']) && !empty(site()->config->hub_settings['secret'])) {
                    $this->setAuthToken(site()->config->hub_settings['auth_token']);
                    $this->setSecret(site()->config->hub_settings['secret']);

                    return site()->config->hub_settings;
                }

                return false;
            }

            /**
             * Save hub auth
             * @param $token
             * @param $secret
             */
            function saveDetails($token, $secret)
            {
                site()->config->hub_settings['auth_token'] = $token;
                site()->config->hub_settings['secret']     = $secret;
                site()->config->save();
                $this->setAuthToken($token);
                $this->setSecret($secret);
            }

        }

    }