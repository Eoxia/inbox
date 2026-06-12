<?php
/**
 *	\file       ajax/get_emails.php
 *	\ingroup    inbox
 *	\brief      Ajax API to get emails
 */

$res = 0;
if (!($res && preg_match('/^http/', $res))) {
	$res = @include '../../main.inc.php';
}
if (!($res && preg_match('/^http/', $res))) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die("Include of main fails");
}

header('Content-Type: application/json');

// Dummy data for V1 demonstrator
$data = array(
	array(
		'id' => 1,
		'sender' => 'Dolibarr Sales',
		'subject' => 'Project Update',
		'date' => '31 mars',
		'snippet' => 'Hi John, just wanted to give you a quick update...',
		'unread' => false,
		'tags' => array(
			array('label' => 'Projet', 'color' => 'blue'),
			array('label' => 'Urgent', 'color' => 'red')
		)
	)
);

echo json_encode($data);
