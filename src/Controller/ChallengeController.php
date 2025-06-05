<?php

namespace Search\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\Session\Container;
use Laminas\Http\Response;
use Omeka\Mvc\Exception\RuntimeException;

class ChallengeController extends AbstractActionController
{


    public function challengeAction()
    {
        $query = $this->params()->fromQuery();
        $site_slug = $this->currentSite()->slug();
        if ($query && array_key_exists('redirect_url', $query) && $query['redirect_url']) {
            $sessionManager = Container::getDefaultManager();
            $session = $sessionManager->getStorage();
            $submission_count = $session->offsetGet($site_slug . '_turnstile_protect_submission_count') ? $session->offsetGet($site_slug . '_turnstile_protect_submission_count') : 0;
            $submission_time = $session->offsetGet($site_slug . '_turnstile_protect_first_submission') ? $session->offsetGet($site_slug . '_turnstile_protect_first_submission') : time();
            // If enough has passed since the first submission reset the count and time
            if (time() > ($submission_time + 3600)) {
                $submission_count = 0;
                $submission_time = time();
            }
            $submission_count++;
            $session->offsetSet($site_slug . '_turnstile_protect_submission_count', $submission_count);
            $session->offsetSet($site_slug . '_turnstile_protect_first_submission', $submission_time);
            if ($submission_count > 5) {
                $response = $this->getResponse();
                $response->setStatusCode(429);
                $view = new ViewModel;
                $view->setTemplate('search/challenge/error');
                return $view;
            }
            $view = new ViewModel;
            $view->setVariable('redirect_url', $query['redirect_url']);
            return $view;
        } else {
            throw new RuntimeException("No redirect URL set.");
        }
    }
    public function verificationAction()
    {
        $response = $this->getResponse();
        $response->setContent(json_encode(['success' => false]));
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $request = $this->getRequest();
        $sessionManager = Container::getDefaultManager();
        $session = $sessionManager->getStorage();
        $site_slug = $this->currentSite()->slug();
        if ($request->isPost() && ($data = json_decode($request->getContent(), true)) && array_key_exists('token', $data) && ($token = $data['token']) && ($key = $this->settings()->get('search_module_turnstile_secret_key', false))) {
            $endpoint = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
            $post_fields = "secret=$key&response=$token";
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
            $verification_response = curl_exec($ch);
            curl_close($ch);
            $verification_response_data = json_decode($verification_response, true);
            if ($verification_response && $verification_response_data && (array_key_exists('success', $verification_response_data)) && ($verification_response_data['success'] == 1)) {
                $response->setContent(json_encode(['success' => true]));
                $session->offsetSet($site_slug . '_turnstile_authorization', true);
                $session->offsetSet($site_slug . '_turnstile_protect_submission_count', 0);
            } elseif ($verification_response && $verification_response_data && (array_key_exists('success', $verification_response_data)) && (!$verification_response_data['success'])) {
                return $response;
            }
            // If there's an invalid response because of network problems give a success rather than delay the user
            else {
                $response->setContent(json_encode(['success' => true]));
                $session->offsetSet($site_slug . '_turnstile_authorization', true);
                $session->offsetSet($site_slug . '_turnstile_protect_submission_count', 0);
            }
        }

        return $response;
    }
}
