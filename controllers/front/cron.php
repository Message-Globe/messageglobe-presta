<?php

class MessageglobesyncCronModuleFrontController extends ModuleFrontController
{
    public $display_header = false;
    public $display_footer = false;
    public $ajax = true;

    public function postProcess()
    {
        header('Content-Type: application/json');

        $token = (string) Tools::getValue('token');
        $limit = (int) Tools::getValue('limit', 25);
        $result = $this->module->runCron($token, $limit);

        http_response_code(!empty($result['success']) ? 200 : 403);
        die(json_encode($result));
    }
}
