<?php

namespace Search\View\Helper;

use Laminas\View\Helper\AbstractHelper;
class SavedQueries extends AbstractHelper
{
    public function __invoke($savedQueries = null)
    {
        $view = $this->getView();

        $savedQueries = $view->api()->search('saved_queries', array('user_id' => $view->identity()->getId()))->getContent();
        
        return $view->partial('search/saved-queries', [
            'savedQueries' => $savedQueries
        ]);
    }
}
