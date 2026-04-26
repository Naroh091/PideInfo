import { startStimulusApp } from '@symfony/stimulus-bundle';
import FileUploaderController from './controllers/file_uploader_controller.js';
// Eager-load the CSRF protection controller so its global submit listener
// always registers — even on pages without `data-controller="csrf-protection"`.
// Otherwise Symfony's stateless double-submit cookie is never set, and
// validation falls back to the origin check; some browsers/extensions strip
// Sec-Fetch-Site/Origin and the user gets "Invalid CSRF token".
import './controllers/csrf_protection_controller.js';

const app = startStimulusApp();
// register any custom, 3rd party controllers here
app.register('file-uploader', FileUploaderController);
