<?php
// Plugin CBT: aturan ujian (Anti Submit, Lock login, Resume timer, Anti Newtab), diatur dari CBT Admin → Tools.
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_cbtujian';
$plugin->version   = 2026112500;
$plugin->requires  = 2024100700; // Moodle 4.5+ (hook output).
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.0';
