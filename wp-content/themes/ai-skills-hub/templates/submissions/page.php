<?php
defined( 'ABSPATH' ) || exit;
get_template_part( 'templates/contributions/shell', null, array( 'frontend' => \AISkillsHub\Core\Submissions\Frontend::class ) );
