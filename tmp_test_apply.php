<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_id('applytest');
session_start();
$_SESSION['user_id'] = 3;
$_SESSION['role'] = 'student';
$_SESSION['current_club_id'] = 'F071';
$_SESSION['form_submit_token'] = 'testtoken';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
  'event_name' => 'CLI test event',
  'responsible_person' => '測試人',
  'event_type' => '校內',
  'activity_location' => '',
  'activity_scale' => '一般活動',
  'description' => 'CLI test',
  'form_token' => 'testtoken',
  'sessions' => [[
    'date' => date('Y-m-d', strtotime('+1 day')),
    'start_time' => '10:30',
    'end_date' => date('Y-m-d', strtotime('+1 day')),
    'end_time' => '12:30',
    'venue_id' => '4',
    'borrow_start' => date('Y-m-d', strtotime('+1 day')).'T09:30',
    'borrow_end' => date('Y-m-d', strtotime('+1 day')).'T16:30',
    'equipment' => []
  ]]
];
$_FILES = [];
include 'student/apply_event.php';
