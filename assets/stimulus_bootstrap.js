import { startStimulusApp } from '@symfony/stimulus-bundle';
import FileUploaderController from './controllers/file_uploader_controller.js';

const app = startStimulusApp();
// register any custom, 3rd party controllers here
app.register('file-uploader', FileUploaderController);
