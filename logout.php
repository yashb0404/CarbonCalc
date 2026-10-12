<?php
require 'includes/auth.php';
mongo_log('logout');
session_destroy();
header('Location: index.php');
