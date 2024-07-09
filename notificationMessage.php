<?php



require_once(__DIR__ . '/../../config.php');

require_once($CFG->libdir.'/messagelib.php'); // Include messaging library


// Create a new message object
$message = new \core\message\message();
$message->component = 'moodle'; // Set the message component
$message->name = 'instantmessage'; // Set the message name
$message->userfrom = \core_user::get_noreply_user(); // Set the message sender
$message->userto = $USER; // Set the message recipient
$message->subject = 'New message notification'; // Set the message subject
$message->fullmessage = 'You have a new message notification in Moodle'; // Set the message body
$message->fullmessageformat = FORMAT_PLAIN; // Set the message body format


// Send the message notification
$messageid = message_send($message);
?>