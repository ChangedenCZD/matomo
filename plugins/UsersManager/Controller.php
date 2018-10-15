<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\UsersManager;

use Endroid\QrCode\QrCode;
use Exception;
use Piwik\API\Request;
use Piwik\API\ResponseBuilder;
use Piwik\Common;
use Piwik\Container\StaticContainer;
use Piwik\Nonce;
use Piwik\Piwik;
use Piwik\Plugin\ControllerAdmin;
use Piwik\Plugins\LanguagesManager\API as APILanguagesManager;
use Piwik\Plugins\LanguagesManager\LanguagesManager;
use Piwik\Plugins\UsersManager\API as APIUsersManager;
use Piwik\Session\SessionNamespace;
use Piwik\SettingsPiwik;
use Piwik\Site;
use Piwik\Tracker\IgnoreCookie;
use Piwik\Translation\Translator;
use Piwik\Url;
use Piwik\View;
use Piwik\Session\SessionInitializer;

class Controller extends ControllerAdmin
{
    const AUTH_CODE_NONCE = 'saveAuthCode';

    /**
     * @var Translator
     */
    private $translator;

    /**
     * @var SystemSettings
     */
    private $settings;

    public function __construct(Translator $translator, SystemSettings $systemSettings)
    {
        $this->translator = $translator;
        $this->settings = $systemSettings;

        parent::__construct();
    }

    static function orderByName($a, $b)
    {
        return strcmp($a['name'], $b['name']);
    }

    /**
     * The "Manage Users and Permissions" Admin UI screen
     */
    public function index()
    {
        Piwik::checkUserIsNotAnonymous();
        Piwik::checkUserHasSomeAdminAccess();

        $view = new View('@UsersManager/index');

        $IdSitesAdmin = Request::processRequest('SitesManager.getSitesIdWithAdminAccess');
        $idSiteSelected = 1;

        if (count($IdSitesAdmin) > 0) {
            $defaultWebsiteId = $IdSitesAdmin[0];
            $idSiteSelected = $this->idSite ?: $defaultWebsiteId;
        }

        if (!Piwik::isUserHasAdminAccess($idSiteSelected) && count($IdSitesAdmin) > 0) {
            // make sure to show a website where user actually has admin access
            $idSiteSelected = $IdSitesAdmin[0];
        }

        $defaultReportSiteName = Site::getNameFor($idSiteSelected);

        $view->idSiteSelected = $idSiteSelected;
        $view->defaultReportSiteName = $defaultReportSiteName;
        $view->currentUserRole = Piwik::hasUserSuperUserAccess() ? 'superuser' : 'admin';
        $view->accessLevels = [
            ['key' => 'noaccess', 'value' => Piwik::translate('UsersManager_PrivNone')],
            ['key' => 'view', 'value' => Piwik::translate('UsersManager_PrivView')],
            ['key' => 'write', 'value' => Piwik::translate('UsersManager_PrivWrite')],
            ['key' => 'admin', 'value' => Piwik::translate('UsersManager_PrivAdmin')],
            ['key' => 'superuser', 'value' => Piwik::translate('Installation_SuperUser'), 'disabled' => true],
        ];
        $view->filterAccessLevels = [
            ['key' => 'noaccess', 'value' => Piwik::translate('UsersManager_PrivNone')],
            ['key' => 'some', 'value' => Piwik::translate('UsersManager_AtLeastView')],
            ['key' => 'view', 'value' => Piwik::translate('UsersManager_PrivView')],
            ['key' => 'write', 'value' => Piwik::translate('UsersManager_PrivWrite')],
            ['key' => 'admin', 'value' => Piwik::translate('UsersManager_PrivAdmin')],
            ['key' => 'superuser', 'value' => Piwik::translate('Installation_SuperUser')],
        ];

        $this->setBasicVariablesView($view);

        return $view->render();
    }

    /**
     * Returns default date for Piwik reports
     *
     * @param string $user
     * @return string today, yesterday, week, month, year
     */
    protected function getDefaultDateForUser($user)
    {
        return APIUsersManager::getInstance()->getUserPreference($user, APIUsersManager::PREFERENCE_DEFAULT_REPORT_DATE);
    }

    /**
     * Returns the enabled dates that users can select,
     * in their User Settings page "Report date to load by default"
     *
     * @throws
     * @return array
     */
    protected function getDefaultDates()
    {
        $dates = array(
            'today'      => $this->translator->translate('Intl_Today'),
            'yesterday'  => $this->translator->translate('Intl_Yesterday'),
            'previous7'  => $this->translator->translate('General_PreviousDays', 7),
            'previous30' => $this->translator->translate('General_PreviousDays', 30),
            'last7'      => $this->translator->translate('General_LastDays', 7),
            'last30'     => $this->translator->translate('General_LastDays', 30),
            'week'       => $this->translator->translate('General_CurrentWeek'),
            'month'      => $this->translator->translate('General_CurrentMonth'),
            'year'       => $this->translator->translate('General_CurrentYear'),
        );

        $mappingDatesToPeriods = array(
            'today' => 'day',
            'yesterday' => 'day',
            'previous7' => 'range',
            'previous30' => 'range',
            'last7' => 'range',
            'last30' => 'range',
            'week' => 'week',
            'month' => 'month',
            'year' => 'year',
        );

        // assertion
        if (count($dates) != count($mappingDatesToPeriods)) {
            throw new Exception("some metadata is missing in getDefaultDates()");
        }

        $allowedPeriods = self::getEnabledPeriodsInUI();
        $allowedDates = array_intersect($mappingDatesToPeriods, $allowedPeriods);
        $dates = array_intersect_key($dates, $allowedDates);

        /**
         * Triggered when the list of available dates is requested, for example for the
         * User Settings > Report date to load by default.
         *
         * @param array &$dates Array of (date => translation)
         */
        Piwik::postEvent('UsersManager.getDefaultDates', array(&$dates));

        return $dates;
    }

    /**
     * The "User Settings" admin UI screen view
     */
    public function userSettings()
    {
        Piwik::checkUserIsNotAnonymous();

        $view = new View('@UsersManager/userSettings');

        $userLogin = Piwik::getCurrentUserLogin();
        $user = Request::processRequest('UsersManager.getUser', array('userLogin' => $userLogin));
        $view->userEmail = $user['email'];
        $view->twoFactorAuthEnabled = !empty($user['twofactor_secret']) && Common::mb_strlen($user['twofactor_secret']) === 32;
        $view->userTokenAuth = Piwik::getCurrentUserTokenAuth();
        $view->canDisable2FA = !$this->settings->twoFactorAuthRequired->getValue();

        $view->ignoreSalt = $this->getIgnoreCookieSalt();

        $userPreferences = new UserPreferences();
        $defaultReport   = $userPreferences->getDefaultReport();

        if ($defaultReport === false) {
            $defaultReport = $userPreferences->getDefaultWebsiteId();
        }

        $view->defaultReport = $defaultReport;

        if ($defaultReport == 'MultiSites') {

            $defaultSiteId = $userPreferences->getDefaultWebsiteId();
            $reportOptionsValue = $defaultSiteId;

            $view->defaultReportIdSite   = $defaultSiteId;
            $view->defaultReportSiteName = Site::getNameFor($defaultSiteId);
        } else {
            $reportOptionsValue = $defaultReport;
            $view->defaultReportIdSite   = $defaultReport;
            $view->defaultReportSiteName = Site::getNameFor($defaultReport);
        }

        $view->defaultReportOptions = array(
            array('key' => 'MultiSites', 'value' => Piwik::translate('General_AllWebsitesDashboard')),
            array('key' => $reportOptionsValue, 'value' => Piwik::translate('General_DashboardForASpecificWebsite')),
        );

        $view->defaultDate = $this->getDefaultDateForUser($userLogin);
        $view->availableDefaultDates = $this->getDefaultDates();

        $languages = APILanguagesManager::getInstance()->getAvailableLanguageNames();
        $languageOptions = array();
        foreach ($languages as $language) {
            $languageOptions[] = array(
                'key' => $language['code'],
                'value' => $language['name']
            );
        }

        $view->languageOptions = $languageOptions;
        $view->currentLanguageCode = LanguagesManager::getLanguageCodeForCurrentUser();
        $view->currentTimeformat = (int) LanguagesManager::uses12HourClockForCurrentUser();
        $view->ignoreCookieSet = IgnoreCookie::isIgnoreCookieFound();
        $view->piwikHost = Url::getCurrentHost();
        $this->setBasicVariablesView($view);

        $view->timeFormats = array(
            '1' => Piwik::translate('General_12HourClock'),
            '0' => Piwik::translate('General_24HourClock')
        );

        return $view->render();
    }

    /**
     * The "Anonymous Settings" admin UI screen view
     */
    public function anonymousSettings()
    {
        Piwik::checkUserHasSuperUserAccess();

        $view = new View('@UsersManager/anonymousSettings');

        $view->availableDefaultDates = $this->getDefaultDates();

        $this->initViewAnonymousUserSettings($view);
        $this->setBasicVariablesView($view);

        return $view->render();
    }

    public function setIgnoreCookie()
    {
        Piwik::checkUserHasSomeViewAccess();
        Piwik::checkUserIsNotAnonymous();

        $salt = Common::getRequestVar('ignoreSalt', false, 'string');
        if ($salt !== $this->getIgnoreCookieSalt()) {
            throw new Exception("Not authorized");
        }

        IgnoreCookie::setIgnoreCookie();
        Piwik::redirectToModule('UsersManager', 'userSettings', array('token_auth' => false));
    }

    /**
     * The Super User can modify Anonymous user settings
     * @param View $view
     */
    protected function initViewAnonymousUserSettings($view)
    {
        if (!Piwik::hasUserSuperUserAccess()) {
            return;
        }

        $userLogin = 'anonymous';

        // Which websites are available to the anonymous users?

        $anonymousSitesAccess = Request::processRequest('UsersManager.getSitesAccessFromUser', array('userLogin' => $userLogin));
        $anonymousSites = array();
        $idSites = array();
        foreach ($anonymousSitesAccess as $info) {
            $idSite = $info['site'];
            $idSites[] = $idSite;

            $site = Request::processRequest('SitesManager.getSiteFromId', array('idSite' => $idSite));
            // Work around manual website deletion
            if (!empty($site)) {
                $anonymousSites[] = array('key' => $idSite, 'value' => $site['name']);
            }
        }
        $view->anonymousSites = $anonymousSites;

        $anonymousDefaultSite = '';

        // Which report is displayed by default to the anonymous user?
        $anonymousDefaultReport = Request::processRequest('UsersManager.getUserPreference', array('userLogin' => $userLogin, 'preferenceName' => APIUsersManager::PREFERENCE_DEFAULT_REPORT));
        if ($anonymousDefaultReport === false) {
            if (empty($anonymousSites)) {
                $anonymousDefaultReport = Piwik::getLoginPluginName();
            } else {
                // we manually imitate what would happen, in case the anonymous user logs in
                // and is redirected to the first website available to him in the list
                // @see getDefaultWebsiteId()
                $anonymousDefaultReport = '1';
                $anonymousDefaultSite = $anonymousSites[0]['key'];
            }
        }

        if (is_numeric($anonymousDefaultReport)) {
            $anonymousDefaultSite = $anonymousDefaultReport;
            $anonymousDefaultReport = '1'; // a website is selected, we make sure "Dashboard for a specific site" gets pre-selected
        }

        if ((empty($anonymousDefaultSite) || !in_array($anonymousDefaultSite, $idSites)) && !empty($idSites)) {
            $anonymousDefaultSite = $anonymousSites[0]['key'];
        }

        $view->anonymousDefaultReport = $anonymousDefaultReport;
        $view->anonymousDefaultSite = $anonymousDefaultSite;
        $view->anonymousDefaultDate = $this->getDefaultDateForUser($userLogin);

        $view->defaultReportOptions = array(
            array('key' => 'Login', 'value' => Piwik::translate('UsersManager_TheLoginScreen')),
            array('key' => 'MultiSites', 'value' => Piwik::translate('General_AllWebsitesDashboard'), 'disabled' => empty($anonymousSites)),
            array('key' => '1', 'value' => Piwik::translate('General_DashboardForASpecificWebsite')),
        );
    }

    /**
     * Records settings for the anonymous users (default report, default date)
     */
    public function recordAnonymousUserSettings()
    {
        $response = new ResponseBuilder(Common::getRequestVar('format'));
        try {
            Piwik::checkUserHasSuperUserAccess();
            $this->checkTokenInUrl();

            $anonymousDefaultReport = Common::getRequestVar('anonymousDefaultReport');
            $anonymousDefaultDate = Common::getRequestVar('anonymousDefaultDate');
            $userLogin = 'anonymous';
            APIUsersManager::getInstance()->setUserPreference($userLogin,
                APIUsersManager::PREFERENCE_DEFAULT_REPORT,
                $anonymousDefaultReport);
            APIUsersManager::getInstance()->setUserPreference($userLogin,
                APIUsersManager::PREFERENCE_DEFAULT_REPORT_DATE,
                $anonymousDefaultDate);
            $toReturn = $response->getResponse();
        } catch (Exception $e) {
            $toReturn = $response->getResponseException($e);
        }

        return $toReturn;
    }

    /**
     * Records settings from the "User Settings" page
     * @throws Exception
     */
    public function recordUserSettings()
    {
        $response = new ResponseBuilder(Common::getRequestVar('format'));
        try {
            $this->checkTokenInUrl();

            $defaultReport = Common::getRequestVar('defaultReport');
            $defaultDate = Common::getRequestVar('defaultDate');
            $language = Common::getRequestVar('language');
            $timeFormat = Common::getRequestVar('timeformat');
            $userLogin = Piwik::getCurrentUserLogin();

            Piwik::checkUserHasSuperUserAccessOrIsTheUser($userLogin);

            $this->processPasswordChange($userLogin);

            LanguagesManager::setLanguageForSession($language);

            Request::processRequest('LanguagesManager.setLanguageForUser', [
                'login' => $userLogin,
                'languageCode' => $language,
            ]);
            Request::processRequest('LanguagesManager.set12HourClockForUser', [
                'login' => $userLogin,
                'use12HourClock' => $timeFormat,
            ]);

            APIUsersManager::getInstance()->setUserPreference($userLogin,
                APIUsersManager::PREFERENCE_DEFAULT_REPORT,
                $defaultReport);
            APIUsersManager::getInstance()->setUserPreference($userLogin,
                APIUsersManager::PREFERENCE_DEFAULT_REPORT_DATE,
                $defaultDate);
            $toReturn = $response->getResponse();
        } catch (Exception $e) {
            $toReturn = $response->getResponseException($e);
        }

        return $toReturn;
    }

    private function noAdminAccessToWebsite($idSiteSelected, $defaultReportSiteName, $message)
    {
        $view = new View('@UsersManager/noWebsiteAdminAccess');

        $view->idSiteSelected = $idSiteSelected;
        $view->defaultReportSiteName = $defaultReportSiteName;
        $view->message = $message;
        $this->setBasicVariablesView($view);

        return $view->render();
    }

    private function processPasswordChange($userLogin)
    {
        $email = Common::getRequestVar('email');
        $newPassword = false;

        $password = Common::getRequestvar('password', false);
        $passwordBis = Common::getRequestvar('passwordBis', false);
        if (!empty($password)
            || !empty($passwordBis)
        ) {
            if ($password != $passwordBis) {
                throw new Exception($this->translator->translate('Login_PasswordsDoNotMatch'));
            }
            $newPassword = $password;
        }

        // UI disables password change on invalid host, but check here anyway
        if (!Url::isValidHost()
            && $newPassword !== false
        ) {
            throw new Exception("Cannot change password with untrusted hostname!");
        }

        Request::processRequest('UsersManager.updateUser', [
            'userLogin' => $userLogin,
            'password' => $newPassword,
            'email' => $email,
        ], $default = []);

        if ($newPassword !== false) {
            $newPassword = Common::unsanitizeInputValue($newPassword);
        }

        // logs the user in with the new password
        if ($newPassword !== false) {
            $sessionInitializer = new SessionInitializer();
            $auth = StaticContainer::get('Piwik\Auth');
            $auth->setLogin($userLogin);
            $auth->setPassword($newPassword);
            $sessionInitializer->initSession($auth);
        }
    }

    /**
     * @return string
     */
    private function getIgnoreCookieSalt()
    {
        return md5(SettingsPiwik::getSalt());
    }

    public function disableTwoFactorAuth()
    {
        Piwik::checkUserIsNotAnonymous();

        if ($this->settings->twoFactorAuthRequired->getValue()) {
            throw new Exception('Two Factor Authentication cannot be disabled');
        }

        $model = new Model();
        $model->updateUserFields(Piwik::getCurrentUserLogin(), array('twofactor_secret' => ''));

        Url::redirectToUrl(Url::getCurrentUrl());

        // todo also disable back up codes
    }

    /**
     * Action to generate a new Google Authenticator secret for the current user
     *
     * @return string
     * @throws \Exception
     * @throws \Piwik\NoAccessException
     */
    public function generateTwoFactorAuth()
    {
        Piwik::checkUserIsNotAnonymous();

        $view = new View('@GoogleAuthenticator/regenerate');
        $this->setGeneralVariablesView($view);

        $googleAuth = StaticContainer::get('GoogleAuthenticator');
        $session = new SessionNamespace('GoogleAuthenticator');

        if (empty($session->secret)) {
            $session->secret = $googleAuth->createSecret(32);
        }

        $secret = $session->secret;
        $session->setExpirationSeconds(180, 'secret');

        $user = $this->getMyUser();
        $authCode = Common::getRequestVar('gaauthcode', '', 'string');
        $authCodeNonce = Common::getRequestVar('authCodeNonce', '', 'string');

        if (!empty($secret) && !empty($authCode) && Nonce::verifyNonce(self::AUTH_CODE_NONCE, $authCodeNonce) &&
            $googleAuth->verifyCode($secret, $authCode, 2)
        ) {
            $this->auth->setAuthCode($authCode);
            if ($this->auth->validateAuthCode()) {
                // todo... include twofactor secret in password reset hash? and the regular session to log other
                // sessions out after changing secret
                $model = new Model();
                $model->updateUserFields($user['login'], array('twofactor_secret' => $secret));

                // todo print back up codes
            }
            Url::redirectToUrl(Url::getCurrentUrl());
        }

        $this->secret = $secret;

        $view->gatitle = $this->settings->twoFactorAuthTitle->getValue();
        $view->description = Piwik::getCurrentUserLogin();
        $view->authCodeNonce = Nonce::getNonce(self::AUTH_CODE_NONCE);
        $view->newSecret = $secret;
        $view->googleAuthImage = $this->getQRUrl($view->description, $view->gatitle);

        return $view->render();
    }

    private function getMyUser()
    {
        $login = Piwik::getCurrentUserLogin();
        $user = Request::processRequest('UsersManager.getUser', array('userLogin' => $login));

        return $user;
    }

    public function showQrCode()
    {
        $session = new SessionNamespace('GoogleAuthenticator');
        $secret = $session->secret;
        if (empty($secret)) {
            throw new Exception('Not possible');
        }
        $title = $this->settings->twoFactorAuthTitle->getValue();
        $descr = Piwik::getCurrentUserLogin();

        $url = 'otpauth://totp/'.urlencode($descr).'?secret='.$secret;
        if(isset($title)) {
            $url .= '&issuer='.urlencode($title);
        }

        $qrCode = new QrCode($url);

        header('Content-Type: '.$qrCode->getContentType());
        echo $qrCode->get();
    }

    protected function getQRUrl($description, $title)
    {
        return sprintf('index.php?module=GoogleAuthenticator&action=showQrCode&cb=%s&title=%s&descr=%s', Common::getRandomString(8), urlencode($title), urlencode($description));
    }

    protected function getCurrentQRUrl()
    {
        return sprintf('index.php?module=GoogleAuthenticator&action=showQrCode&cb=%s&current=1', Common::getRandomString(8));
    }
}
