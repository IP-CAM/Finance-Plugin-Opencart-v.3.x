<?php

class ControllerExtensionPaymentFinancePluginOrder extends Controller
{
    public function index()
	{
        if ($this->request->server['REQUEST_METHOD'] == 'POST' && $this->validate()) {
            echo('hi');
            die();
        }
    }

}