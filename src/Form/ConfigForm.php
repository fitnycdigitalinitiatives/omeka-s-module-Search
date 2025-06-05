<?php

namespace Search\Form;

use Laminas\Form\Form;
use Laminas\Form\Element;

class ConfigForm extends Form
{
    public function init()
    {
        $this->add([
            'name' => 'activate_turnstile',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Activate Cloudflare Turnstile challenge to check for bots',
                // @translate
            ],
            'attributes' => [
                'id' => 'activate_turnstile',
            ],
        ]);
        $this->add([
            'name' => 'turnstile_secret_key',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Secret key for turnstile challenge', // @translate
            ],
            'attributes' => [
                'id' => 'turnstile_secret_key',
            ],
        ]);
    }
}
