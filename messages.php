<?php
    require_once "model.Message.php";
    
    class Messages extends Modules {
        public function __init() {
           $this->addAlias("markup_text", "insert_message_form");
           $this->addAlias("markup_post_text","insert_message_form");
           $this->addAlias("preview", "insert_message_form");
           $this->addAlias("preview_post", "insert_message_form");
           $this->addAlias("message_form", "get_message_form");
        }
        
        static function __install() {
            $sql = SQL::current();
            $sql->query("CREATE TABLE IF NOT EXISTS __messages (
                             id INTEGER PRIMARY KEY AUTO_INCREMENT,
                             body LONGTEXT,
                             author VARCHAR(250) DEFAULT '',
                             author_email VARCHAR(128) DEFAULT '',
                             author_ip INTEGER DEFAULT '0',
                             author_agent VARCHAR(255) DEFAULT '',
                             created_at DATETIME DEFAULT NULL,
                             updated_at DATETIME DEFAULT NULL
                         ) DEFAULT CHARSET=utf8");
                         
            $config = Config::current();
            $config->set("recipient_mail", $config->email);
            $config->set("enable_spam_security", "1");
            $config->set("spam_security_count", "3");
            $config->set("spam_security_timespan", "5");
            $config->set("ip_blacklist", "");
			
			Group::add_permission("send_message", "Send Message");
            Group::add_permission("delete_message", "Delete Messages");
        }
        
        static function __uninstall($confirm) {
            if ($confirm)
                SQL::current()->query("DROP TABLE __messages");
            
            $config = Config::current();
            $config->remove("recipient_mail");
            $config->remove("enable_spam_security");
            $config->remove("spam_security_count");
            $config->remove("spam_security_timespan");
            $config->remove("ip_blacklist");
			
			Group::remove_permission("send_message");
            Group::remove_permission("delete_message");
        }
        
        static function route_message() {
			$config = Config::current();
			
            $error = array();
            if (empty($_POST['name'])) $error[] = 'name';
            if (empty($_POST['email'])) $error[] = 'email';
            if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) $error[] = 'email';
            if (empty($_POST['message'])) $error[] = 'message';
            
            if (count($error) == 0) {
                $name = strip_tags($_POST['name']);
                $email = strip_tags($_POST['email']);
                $message = sanitize_html($_POST['message']);
                
                $continue = true;
                if ($config->enable_spam_security == 1) {            
                    $ip = ip2long($_SERVER['REMOTE_ADDR']);
                    if ($ip === false)
                        $ip = 0;
                
                    $sql = SQL::current();
                    
                    $count = $sql->count("messages", 
                                         "author_ip = :ip AND created_at > DATE_SUB(NOW(), INTERVAL :interval MINUTE)",
                                         array(":ip" => $ip,
                                               ":interval" => $config->spam_security_timespan));
                    
                    if ($count >= $config->spam_security_count)
                        $continue = false;
                }
                
                if(!empty($config->ip_blacklist))
                {
                    $blacklisted_ips = explode(",", preg_replace('/\s+/', '', $config->ip_blacklist));
                    
                    if(in_array($_SERVER['REMOTE_ADDR'], $blacklisted_ips)) 
                        $continue = false;
                }
                
                if ($continue) {
                    Message::create($_POST['message'],
                                    $_POST['name'],
                                    $_POST['email']);
                                   
                    $to = $config->recipient_mail;
                    $subject = $config->name.' - '.__("New Message");
                    $message = "From: ".$name." (".$email.")\n\nMessage: ".$message."\n\nIP: ".strip_tags($_SERVER['REMOTE_ADDR'])."\nUser Agent: ".strip_tags($_SERVER['HTTP_USER_AGENT']);
                    $headers = "From:".$email."\r\n" .
                                           "Reply-To:".$email."\r\n".
                                           "X-Mailer: PHP/".phpversion();
                    $sent = email($to, $subject, $message, $headers);
                    
                    Flash::notice(__("Thank you! Your message was sent."), $_SERVER['HTTP_REFERER']);
                } else {
                    Flash::warning(__("Sorry, your message was rejected due to spam-suspicion. Please try again later."), $_SERVER['HTTP_REFERER']);
                }
            } else {
                $_SESSION['module_messages']['error'] = implode(",", $error);
                $_SESSION['module_messages']['name'] = $_POST['name'];
                $_SESSION['module_messages']['email'] = $_POST['email'];
                $_SESSION['module_messages']['message'] = $_POST['message'];
                
                Flash::warning(__("There was an error sending your message. Please check your details below."), $_SERVER['HTTP_REFERER']);
            }
        }
        
        static function settings_nav($navs) {
            if (Visitor::current()->group->can("change_settings"))
                $navs["messages_settings"] = array("title" => __("Messages", "messages"));

            return $navs;
        }
		
		static function manage_nav($navs) {
            if (!Message::deletable())
                return $navs;

            $sql = SQL::current();
            $message_count = $sql->count("messages");
            $navs["manage_messages"] = array("title" => _f("Messages (%d)", $message_count, "messages"),
                                             "selected" => array("delete_message"));

            return $navs;
        }
		
		static function manage_nav_pages($pages) {
            array_push($pages, "manage_messages", "delete_message");
            return $pages;
        }
        
        static function admin_messages_settings($admin) {
            if (!Visitor::current()->group->can("change_settings"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to change settings."));

            if (empty($_POST))
                return $admin->display("messages_settings");

            if (!isset($_POST['hash']) or $_POST['hash'] != Config::current()->secure_hashkey)
                show_403(__("Access Denied"), __("Invalid security key."));

            $config = Config::current();
            
            $set = array($config->set("recipient_mail", $_POST['recipient_mail']),
                         $config->set("enable_spam_security", isset($_POST['enable_spam_security'])),
                         $config->set("spam_security_count", $_POST['spam_security_count']),
                         $config->set("spam_security_timespan", $_POST['spam_security_timespan']),
                         $config->set("ip_blacklist", $_POST['ip_blacklist']));

            if (!in_array(false, $set))
                Flash::notice(__("Settings updated."), "/admin/?action=messages_settings");
        }
		
		static function admin_manage_messages($admin) {
            if (!Message::deletable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to manage any messages.", "messages"));

            fallback($_GET['query'], "");
            list($where, $params) = keywords($_GET['query'], "body LIKE :query");

            $admin->display("manage_messages",
                            array("messages" => new Paginator(Message::find(array("placeholders" => true,
                                                                                  "where" => $where,
                                                                                  "params" => $params)),
																				  25)));
        }
		
		static function admin_delete_message($admin) {
            $message = new Message($_GET['id']);

            if (!$message->deletable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete this message.", "messages"));

            $admin->display("delete_message", array("message" => $message));
        }
		
		static function admin_destroy_message() {
            if (empty($_POST['id']))
                error(__("No ID Specified"), __("An ID is required to delete a message.", "messages"));

            if ($_POST['destroy'] == "bollocks")
                redirect("/admin/?action=manage_messages");

            if (!isset($_POST['hash']) or $_POST['hash'] != Config::current()->secure_hashkey)
                show_403(__("Access Denied"), __("Invalid security key."));

            $message = new Message($_POST['id']);
            if (!$message->deletable())
                show_403(__("Access Denied"), __("You do not have sufficient privileges to delete this message.", "messages"));

            Message::delete($_POST['id']);

            if (isset($_POST['ajax']))
                exit;

            Flash::notice(__("Message deleted."));
            redirect("/admin/?action=manage_messages");
        }
		
		static function admin_bulk_messages() {
            if (!isset($_POST['message']))
                Flash::warning(__("No messages selected."), "/admin/?action=manage_messages");

            $messages = array_keys($_POST['message']);

            if (isset($_POST['delete'])) {
                foreach ($messages as $message_id) {
                    $message = new Message($message_id);
                    if ($message->deletable())
                        Message::delete($message->id);
                }

                Flash::notice(__("Selected messages deleted.", "messages"));
            }

            redirect("/admin/?action=manage_messages");
        }
        
        public function get_message_form() {
            $formData = null;
            if (isset($_SESSION['module_messages'])) {
                $formData = $_SESSION['module_messages'];
                unset($_SESSION['module_messages']);
            }
            
            if (!Message::sendable())
				return '';
                
            $form_template = THEME_DIR."/forms/message/send.twig";
            if (!file_exists($form_template))
                $form_template = MODULES_DIR."/messages/forms/message/send.twig";
            
            if (!file_exists($form_template))
                return '';
                
            $cache = (is_writable(INCLUDES_DIR."/caches") and
                      !DEBUG and
                      !PREVIEWING and
                      !defined('CACHE_TWIG') or CACHE_TWIG);
            
            $twig = new Twig_Loader(THEME_DIR,
                                    $cache ?
                                    INCLUDES_DIR."/caches" :
                                    null);
            
            return $twig->getTemplate($form_template)->render(array("formData" => $formData));
        }
        
        public function insert_message_form($text) {
            return str_replace('<!--message-form-->', $this->get_message_form(), $text);
        }
    }
