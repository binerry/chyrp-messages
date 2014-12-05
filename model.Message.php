<?php
    /**
     * Class: Message
     * Model for messages SQL table.
     *
     * See Also:
     *     <Model>
     */
    class Message extends Model {
        public $no_results = false;

        /**
         * Function: __construct
         * See Also:
         *     <Model::grab>
         */
        public function __construct($message_id, $options = array()) {
            parent::grab($this, $message_id, $options);

            if ($this->no_results)
                return false;
        }

        /**
         * Function: find
         * See Also:
         *     <Model::search>
         */
        static function find($options = array(), $options_for_object = array()) {
            return parent::search(get_class(), $options, $options_for_object);
        }

        /**
         * Function: create
         * Attempts to create a message using the passed information.
         *
         * Parameters:
         *     $body - Message.
         *     $author - Name of sender.
         *     $email - Sender's email.
         */
        static function create($body, $author, $email) {
            $config = Config::current();
            $route = Route::current();
            $visitor = Visitor::current();
			
			$ip = ip2long($_SERVER['REMOTE_ADDR']);
            if ($ip === false)
                $ip = 0;
			
			$sql = SQL::current();
            $sql->insert("messages",
                         array("body" => sanitize_html($body),
                               "author" => strip_tags($author),
                               "author_email" => strip_tags($email),
                               "author_ip" => $ip,
                               "author_agent" => strip_tags($_SERVER['HTTP_USER_AGENT']),
                               "created_at" => datetime(),
                               "updated_at" => "0000-00-00 00:00:00"));

            $new = new self($sql->latest("messages"));
            Trigger::current()->call("add_message", $new);
            
            return $new;
        }

        static function delete($message_id) {
            $trigger = Trigger::current();
            if ($trigger->exists("delete_message"))
                $trigger->call("delete_message", new self($message_id));

            SQL::current()->delete("messages", array("id" => $message_id));
        }

        public function deletable($user = null) {
            fallback($user, Visitor::current());
            return $user->group->can("delete_message");
        }

        static function sendable($user = null) {
            fallback($user, Visitor::current());
            return $user->group->can("send_message");
        }
    }