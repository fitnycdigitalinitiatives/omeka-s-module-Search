<?php

namespace Search\View\Helper;

use Laminas\View\Helper\AbstractHelper;

class FacetLink extends AbstractHelper
{
    public function __invoke($name, $facet)
    {
        $view = $this->getView();
        $query = $view->params()->fromQuery();
        $route = $view->params()->fromRoute();

        if (strtolower($route["__CONTROLLER__"]) == 'item') {
            $active = false;
            $newQuery = array();
            $newQuery['limit'][$name][] = $facet['value'];
            $itemSetIDs = null;
            if (array_key_exists('item_set_id', $query)) {
                $itemSetIDs = $query['item_set_id'];
            } elseif (array_key_exists('item-set-id', $route)) {
                $itemSetIDs = $route['item-set-id'];
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
            $active = false;
            if (isset($query['limit'][$name]) && false !== array_search($facet['value'], $query['limit'][$name])) {
                $values = $query['limit'][$name];
                $values = array_filter($values, function ($v) use ($facet) {
                    return $v != $facet['value'];
                });
                $query['limit'][$name] = $values;
                $active = true;
            } else {
                $query['limit'][$name][] = $facet['value'];
            }

            unset($query['page']);
        }

        $url = $view->url('site/search', ['__NAMESPACE__' => 'Search\Controller', 'controller' => 'index', 'action' => 'search'], ['query' => $query], true);

        return $view->partial('search/facet-link', [
            'url' => $url,
            'active' => $active,
            'name' => $name,
            'value' => $facet['value'],
            'count' => $facet['count'],
        ]);
    }
}
