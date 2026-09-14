<?php
/**
 * OpenDrive API credentials and target folder.
 * See https://dev.opendrive.com/api
 *
 * ** ห้าม hardcode รหัสจริงในไฟล์นี้ (repo เป็น public) **
 * ตั้งค่าผ่าน environment variable หรือไฟล์ .env เท่านั้น:
 *   OD_USERNAME, OD_PASSWORD, OD_FOLDER_ID, OD_API_BASE
 */
require_once __DIR__ . '/env.php';

$od_username  = rmutimt_env('OD_USERNAME', '');
$od_password  = rmutimt_env('OD_PASSWORD', '');
$od_folder_id = rmutimt_env('OD_FOLDER_ID', '');
/** API root including version (use dev host for Cloudflare / API parity). */
$od_api_base  = rmutimt_env('OD_API_BASE', 'https://dev.opendrive.com/api/v1');
