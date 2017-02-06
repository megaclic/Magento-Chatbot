<?php
	include("Api/Telegram/Telegram.php");

	class Werules_Chatbot_Model_Chatdata extends Mage_Core_Model_Abstract
	{
		// APIs
		private $api_type = "";
		private $tg_bot = "telegram";
		private $fb_bot = "facebook";
		private $wapp_bot = "whatsapp";

		// CONVERSATION STATES
		private $start_state = 0;
		private $list_cat_state = 1;
		private $list_prod_state = 2;
		private $search_state = 3;
		private $login_state = 4;
		private $list_orders_state = 5;
		private $reorder_state = 6;
		private $add2cart_state = 7;
		private $checkout_state = 9;
		private $track_order_state = 10;
		private $support_state = 11;
		private $send_email_state = 12;
		private $clear_cart_state = 13;

		// COMMANDS
		private $cmd_list = "start,list_cat,search,login,list_orders,reorder,add2cart,checkout,clear_cart,track_order,support,send_email";
		private $start_cmd = "";
		private $listacateg_cmd = "";
		private $search_cmd = "";
		private $login_cmd = "";
		private $listorders_cmd = "";
		private $reorder_cmd = "";
		private $add2cart_cmd = "";
		private $checkout_cmd = "";
		private $clearcart_cmd = "";
		private $trackorder_cmd = "";
		private $support_cmd = "";
		private $sendemail_cmd = "";

		// REGEX
		private $unallowed_characters = "/[^A-Za-z0-9 _]/";

		public function _construct()
		{
			//parent::_construct();
			$this->_init('chatbot/chatdata'); // this is location of the resource file.
		}

		public function requestHandler($apiType) // handle request
		{
			$apiKey = $this->getApikey($apiType);
			if ($apiType == $this->tg_bot && $apiKey) // telegram api
			{
				// all logic goes here
				$this->telegramHandler($apiKey);
			}
			else if ($apiType == $this->fb_bot && $apiKey) // facebook api
			{
				// all logic goes here
				$this->facebookHandler($apiKey);
			}
			else
				return "error 101"; // TODO
		}

		private function getApikey($apiType) // check if bot integration is enabled
		{
			if ($apiType == $this->tg_bot) // telegram api
			{
				$enabled = Mage::getStoreConfig('chatbot_enable/telegram_config/enable_bot');
				$apikey = Mage::getStoreConfig('chatbot_enable/telegram_config/telegram_api_key');
				if ($enabled == 1 && $apikey) // is enabled and has API
					return $apikey;
			}
			else if ($apiType == $this->fb_bot)
			{
//				$enabled = Mage::getStoreConfig('chatbot_enable/facebook_config/enable_bot');
//				$apikey = Mage::getStoreConfig('chatbot_enable/facebook_config/facebook_api_key');
//				if ($enabled == 1 && $apikey) // is enabled and has API
//					return $apikey;
				return "error 101"; // TODO
			}
			return null;
		}

		private function addProd2Cart($prodId) // TODO add try / except
		{
			$checkout = Mage::getSingleton('checkout/session');
			$cart = Mage::getModel("checkout/cart");
			try
			{
				if ($this->getSessionId() && $this->getQuoteId())
				{
					$cart->setQuote(Mage::getModel('sales/quote')->loadByIdWithoutStore((int)$this->getQuoteId()));
					$checkout->setSessionId($this->getSessionId());
				}
				$cart->addProduct($prodId);
				$cart->save();
				$checkout->setCartWasUpdated(true);

				$sessionId = $checkout->getEncryptedSessionId();
				$data = array(
					"session_id" => $sessionId,
					"quote_id" => $checkout->getQuote()->getId()
				);
				$this->addData($data);
				$this->save();
			}
			catch (Exception $e)
			{
				return false;
			}

			return true;
		}

		private function valudateCommand($cmd)
		{
			if ($cmd == "/")
				return null;
			return $cmd;
		}

		private function getCommandString($cmdId)
		{
			if ($this->api_type == $this->tg_bot)
				$confpath = 'chatbot_enable/telegram_config/';
			else if ($this->api_type == $this->fb_bot)
				$confpath = 'chatbot_enable/facebook_config/';
//			else if ($this->api_type == $this->wapp_bot)
//				$confpath = 'chatbot_enable/whatsapp_config/';

			$config = Mage::getStoreConfig($confpath . 'enabled_commands');
			$enabledCmds = explode(',', $config);
			if (in_array($cmdId, $enabledCmds))
			{
				$config = Mage::getStoreConfig($confpath . 'commands_code');
				$commands = explode("\n", $config); // command codes split by linebreak
				$defaultCmds = explode(',', $this->cmd_list);
				if (count($commands) < count($defaultCmds))
				{
					return preg_replace( // remove all non-alphanumerics
						$this->unallowed_characters,
						'',
						str_replace( // replace whitespace for underscore
							' ',
							'_',
							trim(
								array_merge( // merge arrays
									$commands,
									array_slice(
										$defaultCmds,
										count($commands)
									)
								)[$cmdId - 1]
							)
						)
					);
				}
				return preg_replace( // remove all non-alphanumerics
					$this->unallowed_characters,
					'',
					str_replace( // replace whitespace for underscore
						' ',
						'_',
						trim(
							$commands[$cmdId - 1]
						)
					)
				);
			}
			return "";
		}

		private function getCommandValue($text, $cmd)
		{
			if (strlen($text) > strlen($cmd))
				return substr($text, strlen($cmd), strlen($text));
			return null;
		}

		private function checkCommand($text, $cmd)
		{
			if ($cmd)
				return substr($text, 0, strlen($cmd)) == $cmd;
			return false;
		}

		private function setState($apiType, $state) // TODO add try
		{
			$data = array($apiType => $state); // data to be insert on database
			$this->addData($data);
			$this->save();
		}

		private function prepareProdMessages($productID)
		{
			$_product = Mage::getModel('catalog/product')->load($productID);
			if ($_product)
			{
				$message = $_product->getName() . "\n" .
					$this->excerpt($_product->getShortDescription(), 60) . "\n" .
					"Add To Cart: " . $this->add2cart_cmd . $_product->getId();
				return $message;
			}
			return null;
		}

		private function loadImageContent($productID)
		{
			$imagepath = Mage::getModel('catalog/product')->load($productID)->getSmallImage();
			if ($imagepath && $imagepath != "no_selection")
			{
				$absolutePath =
					Mage::getBaseDir('media') .
					"/catalog/product" .
					$imagepath;

				return curl_file_create($absolutePath, 'image/jpg');
			}
			return null;
		}

		private function excerpt($text, $size)
		{
			if (strlen($text) > $size)
			{
				$text = substr($text, 0, $size);
				$text = substr($text, 0, strrpos($text, " "));
				$etc = " ...";
				$text = $text . $etc;
			}
			return $text;
		}

		private function getProductIdsBySearch($searchstring)
		{
			$ids = array();
			// Code to Search Product by $searchstring and get Product IDs
			$product_collection = Mage::getResourceModel('catalog/product_collection')
				->addAttributeToSelect('*')
				->addAttributeToFilter('name', array('like' => '%'.$searchstring.'%'))
				->load();

			foreach ($product_collection as $product) {
				$ids[] = $product->getId();
			}
			//return array of product ids
			return $ids;
		}

		private function telegramHandler($apiKey)
		{
			// Instances the Telegram class
			$telegram = new Telegram($apiKey);

			// Take text and chat_id from the message
			$text = $telegram->Text();
			$chat_id = $telegram->ChatID();

			// Instances the model class
			$chatdata = Mage::getModel('chatbot/chatdata')->load($chat_id, 'telegram_chat_id');
			$chatdata->api_type = $this->tg_bot;

			// init commands
			$this->start_cmd = "/start";
			$this->listacateg_cmd = $this->valudateCommand("/" . $chatdata->getCommandString(1));
			$this->search_cmd = $this->valudateCommand("/" . $chatdata->getCommandString(2));
			$this->login_cmd = $this->valudateCommand("/" . $chatdata->getCommandString(3));
			$this->listorders_cmd = $this->valudateCommand("/" . $chatdata->getCommandString(4));
			$this->reorder_cmd = $this->valudateCommand("/" . $chatdata->getCommandString(5));
			$this->add2cart_cmd = $this->valudateCommand("/" . $chatdata->getCommandString(6));
			$this->checkout_cmd = $this->valudateCommand("/" . $chatdata->getCommandString(7));
			$this->clearcart_cmd = $this->valudateCommand("/" . $chatdata->getCommandString(8));
			$this->trackorder_cmd = $this->valudateCommand("/" . $chatdata->getCommandString(9));
			$this->support_cmd = $this->valudateCommand("/" . $chatdata->getCommandString(10));
			$this->sendemail_cmd = $this->valudateCommand("/" . $chatdata->getCommandString(11));

			// TODO DEBUG COMMANDS
//			$temp_var = $this->start_cmd . " - " .
//				$this->listacateg_cmd . " - " .
//				$this->search_cmd . " - " .
//				$this->login_cmd . " - " .
//				$this->listorders_cmd . " - " .
//				$this->reorder_cmd . " - " .
//				$this->add2cart_cmd . " - " .
//				$this->checkout_cmd . " - " .
//				$this->clearcart_cmd . " - " .
//				$this->trackorder_cmd . " - " .
//				$this->support_cmd . " - " .
//				$this->sendemail_cmd;
//			$telegram->sendMessage(array('chat_id' => $chat_id, 'text' => $temp_var));
//			$telegram->sendMessage(array('chat_id' => $chat_id, 'text' => $chatdata->getTelegramConvState()));

			if (!is_null($text) && !is_null($chat_id))
			{
				// supreme commands
				if ($chatdata->checkCommand($text, $this->add2cart_cmd)) // && $chatdata->getTelegramConvState() == $this->list_prod_state TODO
				{
					$cmdvalue = $chatdata->getCommandValue($text, $this->add2cart_cmd);
					if ($cmdvalue)
					{
						if ($chatdata->addProd2Cart($cmdvalue))
							$telegram->sendMessage(array('chat_id' => $chat_id, 'text' => "Added! Send /checkout to checkout."));
						else
							$telegram->sendMessage(array('chat_id' => $chat_id, 'text' => "Something went wrong, try again."));
					}
					return;
				}

				// states
				if ($chatdata->getTelegramConvState() == $this->list_cat_state) // TODO show only in stock products
				{
					$_category = Mage::getModel('catalog/category')->loadByAttribute('name', $text);

					if ($_category)
					{
						$productIDs = $_category->getProductCollection()->getAllIds();
						if ($productIDs)
						{
							$keyb = $telegram->buildKeyBoardHide(true); // hide keyboard built on listing categories
							foreach ($productIDs as $productID)
							{
								$message = $this->prepareProdMessages($productID);
								$image = $this->loadImageContent($productID);
								if ($image)
									$telegram->sendPhoto(array('chat_id' => $chat_id, 'reply_markup' => $keyb, 'photo' => $image, 'caption' => $message));
								else
									$telegram->sendMessage(array('chat_id' => $chat_id, 'reply_markup' => $keyb, 'text' => $message));
							}
							$chatdata->setState('telegram_conv_state', $this->list_prod_state);
						}
						else
							$telegram->sendMessage(array('chat_id' => $chat_id, 'text' => "Sorry, no products found in this category."));
					}
					else
						$telegram->sendMessage(array('chat_id' => $chat_id, 'text' => "Something went wrong, please try again."));
					return;
				}
				else if ($chatdata->getTelegramConvState() == $this->search_state) // TODO
				{
					$chatdata->setState('telegram_conv_state', $this->start_state);
					$productIDs = $this->getProductIdsBySearch($text);
					if ($productIDs)
					{
						foreach ($productIDs as $productID)
						{
							$message = $this->prepareProdMessages($productID);
							$image = $this->loadImageContent($productID);
							if ($image)
								$telegram->sendPhoto(array('chat_id' => $chat_id, 'photo' => $image, 'caption' => $message));
							else
								$telegram->sendMessage(array('chat_id' => $chat_id, 'text' => $message));
						}
					}
					else
						$telegram->sendMessage(array('chat_id' => $chat_id, 'text' => "Sorry, no products found."));

					return;
				}

				// commands
				if ($text == $this->start_cmd)
				{
					// started the bot for the first time
					$chatdata = Mage::getModel('chatbot/chatdata')->load($chat_id, 'telegram_chat_id');
					if ($chatdata->getTelegramChatId()) // TODO
					{
						$message = Mage::getStoreConfig('chatbot_enable/telegram_config/telegram_help_msg'); // TODO
						$content = array('chat_id' => $chat_id, 'text' => $message);
						$telegram->sendMessage($content);

//						$data = array(
//							//'customer_id' => $customerId,
//							'telegram_chat_id' => $chat_id
//						); // data to be insert on database
//						$model = Mage::getModel('chatbot/chatdata')->load($chatdata->getId())->addData($data); // insert data on database
//						$model->setId($chatdata->getId())->save(); // save (duh)
					}
					else // if customer id isnt on our database, means that we need to insert his data
					{
						$message = Mage::getStoreConfig('chatbot_enable/telegram_config/telegram_welcome_msg'); // TODO
						$content = array('chat_id' => $chat_id, 'text' => $message);
						$telegram->sendMessage($content);
						try
						{
							$hash = substr(md5(uniqid($chat_id, true)), 0, 150);
							Mage::getModel('chatbot/chatdata') // using magento model to insert data into database the proper way
								->setTelegramChatId($chat_id)
								->setHashKey($hash) // TODO
								->save();
						}
						catch (Exception $e)
						{
							$telegram->sendMessage(array('chat_id' => $chat_id, 'text' => "Something went wrong, try again.")); // TODO
						}
					}
					return;
				}
				else if ($this->listacateg_cmd && $text == $this->listacateg_cmd)
				{
					$chatdata->setState('telegram_conv_state', $this->list_cat_state);
					$helper = Mage::helper('catalog/category');
					$categories = $helper->getStoreCategories();
					if ($categories)
					{
						$option = array();
						foreach ($categories as $_category) // TODO fix buttons max size
						{
							array_push($option, $_category->getName());
						}

						$keyb = $telegram->buildKeyBoard(array($option));
						$telegram->sendMessage(array('chat_id' => $chat_id, 'reply_markup' => $keyb, 'text' => "Pick a Category"));
					}
					else
						$telegram->sendMessage(array('chat_id' => $chat_id, 'text' => "Something went wrong, try again.")); // TODO
					return;
				}
				else if ($this->checkout_cmd && $text == $this->checkout_cmd) // TODO
				{
					$sessionId = $chatdata->getSessionId();
					$quoteId = $chatdata->getQuoteId();
					if ($sessionId && $quoteId)
					{
						$cartUrl = Mage::helper('checkout/cart')->getCartUrl();
						if (!isset(parse_url($cartUrl)['SID']))
							$cartUrl .= "?SID=" . $sessionId; // add session id to url

						$cart = Mage::getModel('checkout/cart')->setQuote(Mage::getModel('sales/quote')->loadByIdWithoutStore((int)$quoteId));
						$message = "Products on cart:\n";
						foreach ($cart->getQuote()->getItemsCollection() as $item) // TODO
						{
							$message .= $item->getQty() . "x " . $item->getProduct()->getName() . "\n" .
								"Price: " . Mage::helper('core')->currency($item->getProduct()->getPrice(), true, false) . "\n\n";
						}
						$message .= "Total: " .
							Mage::helper('core')->currency($cart->getQuote()->getSubtotal(), true, false) . "\n\n" .
							"[Checkout Here](" . $cartUrl . ")";

						$chatdata->setState('telegram_conv_state', $this->checkout_state);
						$telegram->sendMessage(array('chat_id' => $chat_id, 'parse_mode' => 'Markdown', 'text' => $message));
					}
					else
						$telegram->sendMessage(array('chat_id' => $chat_id, 'text' => "Your cart is empty"));
					return;
				}
				else if ($this->clearcart_cmd && $text == $this->clearcart_cmd)
				{
					$data = array(
						"session_id" => "",
						"quote_id" => ""
					);
					$chatdata->addData($data);
					$chatdata->save();

					$chatdata->setState('telegram_conv_state', $this->clear_cart_state);
					$telegram->sendMessage(array('chat_id' => $chat_id, 'text' => "Cart cleared!"));
					return;
				}
				else if ($this->search_cmd && $text == $this->search_cmd)
				{
					$chatdata->setState('telegram_conv_state', $this->search_state);
					$telegram->sendMessage(array('chat_id' => $chat_id, 'text' => "Okay, search for what?"));
					return;
				}
				else if ($this->login_cmd && $text == $this->login_cmd) // TODO
				{
					$chatdata->setState('telegram_conv_state', $this->login_state);
					return;
				}
				else if ($this->listorders_cmd && $text == $this->listorders_cmd) // TODO
				{
					$chatdata->setState('telegram_conv_state', $this->list_orders_state);
					return;
				}
				else if ($this->reorder_cmd && $text == $this->reorder_cmd) // TODO
				{
					$chatdata->setState('telegram_conv_state', $this->reorder_state);
					return;
				}
				else if ($this->trackorder_cmd && $text == $this->trackorder_cmd) // TODO
				{
					$chatdata->setState('telegram_conv_state', $this->track_order_state);
					return;
				}
				else if ($this->support_cmd && $text == $this->support_cmd) // TODO
				{
					$chatdata->setState('telegram_conv_state', $this->support_state);
					return;
				}
				else if ($this->sendemail_cmd && $text == $this->sendemail_cmd) // TODO
				{
					$chatdata->setState('telegram_conv_state', $this->send_email_state);
					return;
				}
				else return "error 101"; // TODO
			}
		}

		private function facebookHandler($apiKey)
		{

		}

		private function whatsappHandler($apiKey)
		{

		}
	}