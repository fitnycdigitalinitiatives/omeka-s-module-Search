<?php

namespace Search\View\Helper;

use Laminas\View\Helper\AbstractHelper;

class GetSearchFormForSite extends AbstractHelper
{
    public function __invoke($site, $partial = null)
    {
        $api = $this->getView()->plugin('api');
        $searchPages = $api->search('search_pages')->getContent();
        $currentSiteID = $site->id();
        foreach ($searchPages as $searchPage) {
            if (array_key_exists('site', $searchPage->settings()) && ($currentSiteID == $searchPage->settings()['site'])) {
                $searchForm = $this->getView()->plugin('searchForm');
                return $searchForm($searchPage, $partial);
            }
        }
        return null;
    }
}
