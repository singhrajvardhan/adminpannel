// /raj/assets/js/onboarding.js - UPLOAD SECTION (Replace the upload functions)

// File Upload Handler with Camera Support - FIXED FOR DESKTOP
function initFileUpload(type) {
    const configs = {
        'resume': {
            dropZone: 'resumeDropZone',
            fileInput: 'resumeFile',
            uploadBtn: 'resumeUploadBtn',
            preview: 'resumePreview',
            field: 'resume',
            accept: '.pdf,.jpg,.jpeg,.png',
            allowedTypes: ['application/pdf', 'image/jpeg', 'image/png'],
            typeName: 'resume'
        },
        'pan': {
            dropZone: 'panDropZone',
            fileInput: 'panFile',
            uploadBtn: 'panUploadBtn',
            cameraBtn: 'panCameraBtn',
            preview: 'panPreview',
            field: 'pan_image',
            accept: 'image/jpeg,image/png',
            allowedTypes: ['image/jpeg', 'image/png'],
            typeName: 'pan'
        },
        'aadhaar': {
            front: {
                dropZone: 'aadhaarFrontDropZone',
                fileInput: 'aadhaarFrontFile',
                uploadBtn: 'aadhaarFrontBtn',
                cameraBtn: 'aadhaarFrontCameraBtn',
                preview: 'aadhaarFrontPreview',
                field: 'aadhaar_front',
                typeName: 'aadhaar_front'
            },
            back: {
                dropZone: 'aadhaarBackDropZone',
                fileInput: 'aadhaarBackFile',
                uploadBtn: 'aadhaarBackBtn',
                cameraBtn: 'aadhaarBackCameraBtn',
                preview: 'aadhaarBackPreview',
                field: 'aadhaar_back',
                typeName: 'aadhaar_back'
            }
        },
        'photo': {
            dropZone: 'photoDropZone',
            fileInput: 'photoFile',
            uploadBtn: 'photoUploadBtn',
            cameraBtn: 'photoCameraBtn',
            preview: 'photoPreview',
            field: 'candidate_photo',
            accept: 'image/jpeg,image/png',
            allowedTypes: ['image/jpeg', 'image/png'],
            typeName: 'photo'
        },
        'house': {
            house1: {
                dropZone: 'house1DropZone',
                fileInput: 'house1File',
                uploadBtn: 'house1Btn',
                cameraBtn: 'house1CameraBtn',
                preview: 'house1Preview',
                field: 'house_photo_1',
                typeName: 'house1'
            },
            house2: {
                dropZone: 'house2DropZone',
                fileInput: 'house2File',
                uploadBtn: 'house2Btn',
                cameraBtn: 'house2CameraBtn',
                preview: 'house2Preview',
                field: 'house_photo_2',
                typeName: 'house2'
            },
            street: {
                dropZone: 'streetDropZone',
                fileInput: 'streetFile',
                uploadBtn: 'streetBtn',
                cameraBtn: 'streetCameraBtn',
                preview: 'streetPreview',
                field: 'street_photo',
                typeName: 'street'
            }
        }
    };
    
    if (type === 'resume') {
        setupUpload(configs.resume);
    } else if (type === 'pan') {
        setupUpload(configs.pan);
    } else if (type === 'aadhaar') {
        setupUpload(configs.aadhaar.front);
        setupUpload(configs.aadhaar.back);
    } else if (type === 'photo') {
        setupUpload(configs.photo);
    } else if (type === 'house') {
        setupUpload(configs.house.house1);
        setupUpload(configs.house.house2);
        setupUpload(configs.house.street);
    }
}

function setupUpload(config) {
    const dropZone = document.getElementById(config.dropZone);
    const fileInput = document.getElementById(config.fileInput);
    const uploadBtn = document.getElementById(config.uploadBtn);
    const cameraBtn = document.getElementById(config.cameraBtn);
    const preview = document.getElementById(config.preview);
    
    if (!dropZone || !fileInput) {
        console.log('Upload config missing:', config);
        return;
    }
    
    // Upload button click
    if (uploadBtn) {
        uploadBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            fileInput.click();
        });
    }
    
    // Drop zone click (for file browser)
    dropZone.addEventListener('click', function(e) {
        // Don't trigger if click was on a button
        if (e.target.closest('button')) return;
        fileInput.click();
    });
    
    // Drag and drop
    dropZone.addEventListener('dragover', function(e) {
        e.preventDefault();
        this.classList.add('dragover');
    });
    
    dropZone.addEventListener('dragleave', function(e) {
        e.preventDefault();
        this.classList.remove('dragover');
    });
    
    dropZone.addEventListener('drop', function(e) {
        e.preventDefault();
        this.classList.remove('dragover');
        if (e.dataTransfer.files.length) {
            fileInput.files = e.dataTransfer.files;
            handleFileUpload(fileInput, config, preview);
        }
    });
    
    // File input change
    fileInput.addEventListener('change', function() {
        if (this.files && this.files.length) {
            handleFileUpload(this, config, preview);
        }
    });
    
    // Camera button
    if (cameraBtn) {
        cameraBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            // Check if camera is supported
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                showToast('Camera not supported. Please upload a file instead.', 'warning');
                return;
            }
            
            const type = config.typeName || 'document';
            capturePhoto(function(dataUrl) {
                // Convert data URL to file
                fetch(dataUrl)
                    .then(res => res.blob())
                    .then(blob => {
                        const fileName = 'camera-capture-' + Date.now() + '.jpg';
                        const file = new File([blob], fileName, { type: 'image/jpeg' });
                        const dataTransfer = new DataTransfer();
                        dataTransfer.items.add(file);
                        fileInput.files = dataTransfer.files;
                        handleFileUpload(fileInput, config, preview);
                    })
                    .catch(err => {
                        console.error('Error processing camera image:', err);
                        showToast('Error processing image. Please try again.', 'error');
                    });
            }, type);
        });
    }
}

function handleFileUpload(fileInput, config, preview) {
    if (!fileInput.files || !fileInput.files.length) {
        showToast('No file selected.', 'warning');
        return;
    }
    
    const file = fileInput.files[0];
    
    // Validate file size
    if (file.size > 5 * 1024 * 1024) {
        showToast('File too large. Maximum 5MB allowed.', 'error');
        fileInput.value = '';
        return;
    }
    
    // Validate file type
    const allowedTypes = config.allowedTypes || ['image/jpeg', 'image/png', 'application/pdf'];
    const fileType = file.type;
    const fileExt = file.name.split('.').pop().toLowerCase();
    
    // Check by MIME type and extension
    let isValid = false;
    for (let type of allowedTypes) {
        if (fileType === type) {
            isValid = true;
            break;
        }
        // Check by extension for some types
        if (type === 'application/pdf' && fileExt === 'pdf') isValid = true;
        if (type === 'image/jpeg' && ['jpg', 'jpeg'].includes(fileExt)) isValid = true;
        if (type === 'image/png' && fileExt === 'png') isValid = true;
    }
    
    if (!isValid) {
        showToast('Invalid file type. Allowed: ' + allowedTypes.join(', '), 'error');
        fileInput.value = '';
        return;
    }
    
    // Prepare form data
    const formData = new FormData();
    formData.append('agent_id', agentId);
    formData.append('type', config.typeName || 'document');
    formData.append('file', file);
    
    // Show loading
    const uploadBtn = document.getElementById(config.uploadBtn);
    if (uploadBtn) {
        uploadBtn.textContent = '⏳ Uploading...';
        uploadBtn.disabled = true;
    }
    
    // Upload
    fetch('/raj/api/upload-document.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (uploadBtn) {
            uploadBtn.textContent = config.typeName === 'resume' ? '📤 Choose File' : '📤 Upload';
            uploadBtn.disabled = false;
        }
        
        if (data.success) {
            // Update step data
            stepData[config.field] = data.path;
            showToast('✅ File uploaded successfully!', 'success');
            
            // Show preview
            if (preview) {
                if (file.type && file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        preview.innerHTML = `
                            <div class="file-preview">
                                <img src="${e.target.result}" style="max-width:80px; max-height:80px; border-radius:4px; object-fit:cover;">
                                <div class="file-info">
                                    <div class="file-name">${file.name}</div>
                                    <div class="file-size">${(file.size / 1024).toFixed(1)} KB</div>
                                </div>
                                <span style="color:#28a745; font-weight:600;">✅ Uploaded</span>
                            </div>
                        `;
                    };
                    reader.readAsDataURL(file);
                } else {
                    preview.innerHTML = `
                        <div class="file-preview">
                            <span style="font-size:30px;">📄</span>
                            <div class="file-info">
                                <div class="file-name">${file.name}</div>
                                <div class="file-size">${(file.size / 1024).toFixed(1)} KB</div>
                            </div>
                            <span style="color:#28a745; font-weight:600;">✅ Uploaded</span>
                        </div>
                    `;
                }
            }
            
            // Auto-save step data
            saveStepData(config.field, data.path);
            
        } else {
            showToast('Upload failed: ' + data.message, 'error');
            fileInput.value = '';
        }
    })
    .catch(err => {
        console.error('Upload error:', err);
        showToast('Network error. Please try again.', 'error');
        fileInput.value = '';
        if (uploadBtn) {
            uploadBtn.textContent = config.typeName === 'resume' ? '📤 Choose File' : '📤 Upload';
            uploadBtn.disabled = false;
        }
    });
}

function saveStepData(field, value) {
    fetch('/raj/api/save-step.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            agent_id: agentId,
            csrf_token: csrfToken,
            step: currentStep,
            data: JSON.stringify({ [field]: value })
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            console.log('Field saved:', field);
        }
    })
    .catch(err => console.error('Save error:', err));
}