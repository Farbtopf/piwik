<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik_Plugins
 * @package Overlay
 */
namespace Piwik\Plugins\Overlay;

use Exception;
use Piwik\Access;
use Piwik\Config;
use Piwik\DataTable;
use Piwik\Piwik;
use Piwik\Plugins\SitesManager\API as APISitesManager;
use Piwik\Plugins\SitesManager\SitesManager;
use Piwik\Plugins\Transitions\API as APITransitions;
use Piwik\Tracker\Action;

class API extends \Piwik\Plugin\API
{
    /**
     * Get translation strings
     */
    public function getTranslations($idSite)
    {
        $this->authenticate($idSite);

        $translations = array(
            'oneClick'         => 'Overlay_OneClick',
            'clicks'           => 'Overlay_Clicks',
            'clicksFromXLinks' => 'Overlay_ClicksFromXLinks',
            'link'             => 'Overlay_Link'
        );

        return array_map(array('\\Piwik\\Piwik','translate'), $translations);
    }

    /**
     * Get excluded query parameters for a site.
     * This information is used for client side url normalization.
     */
    public function getExcludedQueryParameters($idSite)
    {
        $this->authenticate($idSite);

        $sitesManager = APISitesManager::getInstance();
        $site = $sitesManager->getSiteFromId($idSite);

        try {
            return SitesManager::getTrackerExcludedQueryParameters($site);
        } catch (Exception $e) {
            // an exception is thrown when the user has no view access.
            // do not throw the exception to the outside.
            return array();
        }
    }

    /**
     * Get following pages of a url.
     * This is done on the logs - not the archives!
     *
     * Note: if you use this method via the regular API, the number of results will be limited.
     * Make sure, you set filter_limit=-1 in the request.
     */
    public function getFollowingPages($url, $idSite, $period, $date, $segment = false)
    {
        $this->authenticate($idSite);

        $url = Action::excludeQueryParametersFromUrl($url, $idSite);
        // we don't unsanitize $url here. it will be done in the Transitions plugin.

        $resultDataTable = new DataTable;

        try {
            $limitBeforeGrouping = Config::getInstance()->General['overlay_following_pages_limit'];
            $transitionsReport = APITransitions::getInstance()->getTransitionsForAction(
                $url, $type = 'url', $idSite, $period, $date, $segment, $limitBeforeGrouping,
                $part = 'followingActions', $returnNormalizedUrls = true);
        } catch (Exception $e) {
            return $resultDataTable;
        }

        $reports = array('followingPages', 'outlinks', 'downloads');
        foreach ($reports as $reportName) {
            if (!isset($transitionsReport[$reportName])) {
                continue;
            }
            foreach ($transitionsReport[$reportName]->getRows() as $row) {
                // don't touch the row at all for performance reasons
                $resultDataTable->addRow($row);
            }
        }

        return $resultDataTable;
    }

    /** Do cookie authentication. This way, the token can remain secret. */
    private function authenticate($idSite)
    {
        /**
         * This event is triggered shortly before the user is authenticated. Use it to create your own
         * authentication object instead of the Piwik authentication. Make sure to implement the `Piwik\Auth`
         * interface in case you want to define your own authentication.
         */
        Piwik::postEvent('Request.initAuthenticationObject', array($allowCookieAuthentication = true));

        $auth = \Piwik\Registry::get('auth');
        $success = Access::getInstance()->reloadAccess($auth);

        if (!$success) {
            throw new Exception('Authentication failed');
        }

        Piwik::checkUserHasViewAccess($idSite);
    }
}
