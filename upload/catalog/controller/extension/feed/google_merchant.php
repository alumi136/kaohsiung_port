<?php
class ControllerExtensionFeedGoogleMerchant extends Controller {
	public function index() {	
	$log = new Log('google_merchant.log');  

	$this->load->model('tool/image');  

	$start = microtime(true);	
	$output  = '<?xml version="1.0" encoding="UTF-8" ?>';
	$output .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">';
	$output .= '  <channel>';
	$output .= '  <title><![CDATA[' . html_entity_decode($this->config->get('config_name'), ENT_QUOTES, 'UTF-8') . ']]></title>';
	$output .= '  <description><![CDATA[' . html_entity_decode($this->config->get('config_meta_description'), ENT_QUOTES, 'UTF-8') . ']]></description>';
	$output .= '  <link>' . $this->config->get('config_url') . '</link>';

	$this->load->model('catalog/product');

	$products = $this->model_catalog_product->getProducts();	
	if ($products) {
		foreach ($products as $product) {
			$output .= "<item>\n";
			$output .= "  <g:id>"  .$product['model']. "</g:id>\n";
			$output .= "  <g:title>" .  htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') . "</g:title>\n";
			$output .= "  <g:description><![CDATA[" . strip_tags(html_entity_decode(preg_replace('/\s+/', ' ',$product['description']), ENT_QUOTES, 'UTF-8')) . "]]></g:description>\n";
			$output .= "  <g:link>" . $this->url->link('product/product', 'product_id=' . $product['product_id']) ."</g:link>\n";

			if (!empty($product['image']) && is_file(DIR_IMAGE.$product['image'])) {	   
				if (VERSION >= '2.2.0.0') {
					$width = $this->config->get('theme_default_image_popup_width');
					$height = $this->config->get('theme_default_image_popup_height');
				} else {
					$width = $this->config->get('config_image_popup_width');
					$height = $this->config->get('config_image_popup_height'); 
				}

				$output .= '  <g:image_link>' . @$this->model_tool_image->resize($product['image'],$width,$height) . "</g:image_link>\n";

				// change new to used if you sell used goods		   
				$output .= "  <g:condition>new</g:condition>\n";
				$output .= "  <g:quantity>" . $product['quantity'] . "</g:quantity>\n";

			   	$currencies = array(
					'USD',
					'EUR',
					'TWD'
				);

				if (in_array($this->session->data['currency'], $currencies)) {
					$currency_code = $this->session->data['currency'];
					$currency_value = $this->currency->getValue($this->session->data['currency']);
				} else {
					$currency_code = 'TWD';
					$currency_value = $this->currency->getValue('TWD');
				}

				if ((float)$product['special']) {
					//$output .= "  <g:price>" .  $this->currency->format($this->tax->calculate($product['special'], $product['tax_class_id']), $currency_code, $currency_value, true) . "</g:price>\n";
					$output .= "  <g:price>" .  round($this->tax->calculate($product['special'], $product['tax_class_id'])) . ' ' . $currency_code . "</g:price>\n";
				} else {
					//$output .= "  <g:price>" . $this->currency->format($this->tax->calculate($product['price'], $product['tax_class_id']), $currency_code, $currency_value, true) . "</g:price>\n";
					$output .= "  <g:price>" . round($this->tax->calculate($product['price'], $product['tax_class_id'])) . ' ' . $currency_code . "</g:price>\n";
				}
			
				$output .= "  <g:gtin>" . $product['ean'] . "</g:gtin>\n";
				$output .= "  <g:brand>" . htmlspecialchars($product['manufacturer'], ENT_QUOTES, 'UTF-8') ."</g:brand>\n";
				$output .= "  <g:availability><![CDATA[" . ($product['quantity'] ? 'in stock' : 'out of stock') . "]]></g:availability>\n";
				/*$output .= "  <g:adult>yes</g:adult>\n";*/ //for adult sales 
			}

			$output .= "</item>\n";
		}
	}

	$output .= "</channel>\n";
	$output .= "</rss>\n";
	$time = microtime(true) - $start;
	//$log->write(sprintf('Sitemap was generated for %.4F s. ', $time).'Request from '.$_SERVER["REMOTE_ADDR"].' '.$_SERVER['HTTP_USER_AGENT']);

	$this->response->addHeader('Content-Type: application/rss+xml');
	$this->response->setOutput($output);	 

	}
}