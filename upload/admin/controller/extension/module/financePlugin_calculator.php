<?php
class ControllerExtensionModuleFinancePluginCalculator extends Controller {
	private $error = array();

	public function index() {
		$this->load->language('extension/module/financePlugin_calculator');
		$this->load->language('extension/module/financePlugin');
		$this->load->model('setting/setting');

		$this->document->setTitle($this->language->get('plugin_title'));

		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			$this->model_setting_setting->editSetting('module_financePlugin_calculator', $this->request->post);
			$this->session->data['success'] = $this->language->get('calculator_edit_success_msg');
			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true));
		}

		if (isset($this->error['warning'])) {
			$data['error_warning'] = $this->error['warning'];
		} else {
			$data['error_warning'] = '';
		}

		$data['breadcrumbs'] = array();

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('home_label'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('extensions_label'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/module/financePlugin_calculator', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['action_url'] = $this->url->link('extension/module/financePlugin_calculator', 'user_token=' . $this->session->data['user_token'], true);

		$data['cancel_url'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true);

		if (isset($this->request->post['module_financePlugin_calculator_status'])) {
			$data['module_financePlugin_calculator_status'] = $this->request->post['module_financePlugin_calculator_status'];
		} else {
			$data['module_financePlugin_calculator_status'] = $this->config->get('module_financePlugin_calculator_status');
		}

		$data['header_tpl'] = $this->load->controller('common/header');
		$data['column_left_tpl'] = $this->load->controller('common/column_left');
		$data['footer_tpl'] = $this->load->controller('common/footer');
		
		$this->response->setOutput($this->load->view('extension/module/financePlugin_calculator', $data));
	}

	protected function validate() {
		if (!$this->user->hasPermission('modify', 'extension/module/financePlugin_calculator')) {
			$this->error['warning'] = $this->language->get('calculator_permission_error_msg');
		}

		return !$this->error;
	}
}
