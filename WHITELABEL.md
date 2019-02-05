# Whitelabel Finance Plugin

## Instructions

To whitelabel the plugin to a particular client, replace the use of the word "divido" with 
the handle of the company you are setting up, in the following locations:

- Open **upload/catalog/view/theme/default/template/extension/module/financePlugin_calculator.twig** 
file
  - Replace "divido" with the unique company key in lines 7-14

- Open **upload/catalog/view/theme/default/template/extension/module/financePlugin.twig** file
  - Replace "divido" with the unique company key in lines 5, 55 & 13-20

- Open **upload/catalog/view/theme/default/template/extension/module/financePlugin_widget.twig** 
file
  - Replace "divido" with the unique company key in lines 1-4

- Open the **upload/admin/language/en-gb/extension/payment/financePlugin.php** file
  - Change the reference to *Finance Plugin* to the preferred name of your plugin in lines 2, 3, 6 
  & 28

- Open the **upload/admin/language/en-gb/extension/module/financePlugin_calculator.php** file
  - Change the reference to *Finance Plugin Product Page Calculator* to the preferred name of your 
  plugin widget in lines 3, 7, 8 & 14

