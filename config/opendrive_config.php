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

$od_username  = getenv('OD_USERNAME') ?: '';
$od_password  = getenv('OD_PASSWORD') ?: '';
$od_folder_id = getenv('OD_FOLDER_ID') ?: '';
/** API root including version (use dev host for Cloudflare / API parity). */
$od_api_base  = getenv('OD_API_BASE') ?: 'https://dev.opendrive.com/api/v1';
