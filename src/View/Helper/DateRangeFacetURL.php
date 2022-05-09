<?php

namespace Search\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Laminas\Mvc\Application;

class DateRangeFacetURL extends AbstractHelper
{
    public function __construct(Application $application)
    {
        $this->application = $application;
    }

    public function __invoke()
    {
        $mvcEvent = $this->application->getMvcEvent();
        $routeMatch = $mvcEvent->getRouteMatch();
        $request = $mvcEvent->getRequest();

        $route = $routeMatch->getMatchedRouteName();
        $params = $routeMatch->getParams();
        $query = $request->getQuery()->toArray();

        if ($route == 'site/resource' || $route == 'site/item-set') {
            $active = false;
            $newQuery = array();
            $itemSetIDs = null;
            if (array_key_exists('item_set_id', $query)) {
                $itemSetIDs = $query['item_set_id'];
            } elseif (array_key_exists('item-set-id', $params)) {
                $itemSetIDs = $params['item-set-id'];
            }
            if ($itemSetIDs) {
                if (!is_array($itemSetIDs)) {
                    $itemSetIDs = [$itemSetIDs];
                }
                $itemSetIDs = array_filter($itemSetIDs);
                if ($itemSetIDs) {
                    $newQuery['item_set_id'] = $itemSetIDs;
                }
            }
            $query = $newQuery;
        } else {
            // Remove any date range already set
            if (isset($query['date_range_start'])) {
                unset($query['date_range_start']);
            }
            if (isset($query['date_range_end'])) {
                unset($query['date_range_end']);
            }
            unset($query['page']);
        }




        $view = $this->getView();
        return $view->url('site/search', ['__NAMESPACE__' => 'Search\Controller', 'controller' => 'index', 'action' => 'search'], ['query' => $query], true);
    }
}
