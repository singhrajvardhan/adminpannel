// /raj/assets/js/camera.js
class CameraCapture {
    constructor() {
        this.video = null;
        this.canvas = null;
        this.image = null;
        this.status = null;
        this.captureBtn = null;
        this.retakeBtn = null;
        this.useBtn = null;
        this.modal = null;
        this.closeBtn = null;
        this.title = null;
        this.switchCameraBtn = null;
        this.facingMode = 'user'; // 'user' for front, 'environment' for back
        
        this.stream = null;
        this.capturedData = null;
        this.callback = null;
        this.type = 'photo';
        this.isDesktop = !('ontouchstart' in window) && navigator.maxTouchPoints === 0;
        
        this.init();
    }
    
    init() {
        // Create modal if it doesn't exist
        if (!document.getElementById('cameraModal')) {
            this.createModal();
        }
        
        this.modal = document.getElementById('cameraModal');
        this.video = document.getElementById('cameraPreview');
        this.canvas = document.getElementById('cameraCanvas');
        this.image = document.getElementById('capturedImage');
        this.status = document.getElementById('cameraStatus');
        this.captureBtn = document.getElementById('captureBtn');
        this.retakeBtn = document.getElementById('retakeBtn');
        this.useBtn = document.getElementById('usePhotoBtn');
        this.closeBtn = document.getElementById('closeCamera');
        this.title = document.getElementById('cameraTitle');
        this.switchCameraBtn = document.getElementById('switchCameraBtn');
        
        if (this.captureBtn) {
            this.captureBtn.addEventListener('click', () => this.capture());
        }
        if (this.retakeBtn) {
            this.retakeBtn.addEventListener('click', () => this.retake());
        }
        if (this.useBtn) {
            this.useBtn.addEventListener('click', () => this.usePhoto());
        }
        if (this.closeBtn) {
            this.closeBtn.addEventListener('click', () => this.close());
        }
        if (this.switchCameraBtn) {
            this.switchCameraBtn.addEventListener('click', () => this.switchCamera());
        }
        if (this.modal) {
            this.modal.addEventListener('click', (e) => {
                if (e.target === this.modal) this.close();
            });
        }
        
        // Handle resize
        window.addEventListener('resize', () => {
            if (this.stream && this.video) {
                this.adjustVideoSize();
            }
        });
    }
    
    createModal() {
        const modalHTML = `
            <div id="cameraModal" class="modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); z-index:9999; align-items:center; justify-content:center;">
                <div class="modal-content" style="background:white; border-radius:16px; padding:25px; max-width:650px; width:92%; max-height:95vh; overflow-y:auto; position:relative;">
                    <span class="close" id="closeCamera" style="position:absolute; top:12px; right:18px; font-size:30px; cursor:pointer; color:#999; z-index:10; background:rgba(255,255,255,0.8); border-radius:50%; width:40px; height:40px; display:flex; align-items:center; justify-content:center; transition:all 0.3s;">&times;</span>
                    <h3 id="cameraTitle" style="color:#1a3c6e; margin-bottom:10px; font-size:20px;">📸 Capture Photo</h3>
                    <div id="cameraContainer" style="margin:10px 0; text-align:center; background:#000; border-radius:8px; overflow:hidden; position:relative; min-height:200px;">
                        <video id="cameraPreview" autoplay playsinline style="width:100%; max-height:450px; background:#000; display:block;"></video>
                        <canvas id="cameraCanvas" style="display:none;"></canvas>
                        <img id="capturedImage" style="display:none; max-width:100%; max-height:450px; margin:0 auto;">
                        <div id="cameraPlaceholder" style="display:flex; align-items:center; justify-content:center; height:250px; color:#666; font-size:16px; background:#1a1a1a; color:#888;">
                            🎥 Camera initializing...
                        </div>
                    </div>
                    <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap; margin-top:15px;">
                        <button id="captureBtn" class="btn btn-primary" style="padding:12px 30px; border:none; border-radius:8px; background:linear-gradient(135deg, #1a3c6e, #2a5c8e); color:white; font-weight:600; cursor:pointer; font-size:16px; min-width:120px;">📸 Capture</button>
                        <button id="retakeBtn" class="btn btn-secondary" style="display:none; padding:12px 30px; border:none; border-radius:8px; background:#6c757d; color:white; font-weight:600; cursor:pointer; font-size:16px; min-width:120px;">🔄 Retake</button>
                        <button id="usePhotoBtn" class="btn btn-success" style="display:none; padding:12px 30px; border:none; border-radius:8px; background:#28a745; color:white; font-weight:600; cursor:pointer; font-size:16px; min-width:120px;">✅ Use Photo</button>
                        <button id="switchCameraBtn" class="btn btn-secondary" style="padding:12px 20px; border:none; border-radius:8px; background:#17a2b8; color:white; font-weight:600; cursor:pointer; font-size:14px;">🔄 Switch Camera</button>
                    </div>
                    <p id="cameraStatus" style="margin-top:12px; color:#666; text-align:center; font-size:14px;">📷 Click "Capture" to take a photo</p>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHTML);
    }
    
    open(callback, type = 'photo') {
        this.callback = callback;
        this.type = type;
        this.facingMode = (type === 'photo' || type === 'user') ? 'user' : 'environment';
        this.modal.style.display = 'flex';
        
        // Set title
        const titles = {
            'photo': '📸 Capture Photo',
            'document': '📸 Capture Document',
            'pan': '📸 Capture PAN Card',
            'aadhaar': '📸 Capture Aadhaar',
            'house': '📸 Capture House Photo',
            'user': '📸 Capture Photo'
        };
        this.title.textContent = titles[type] || '📸 Capture Photo';
        
        this.status.textContent = '⏳ Initializing camera...';
        this.capturedData = null;
        this.image.style.display = 'none';
        this.video.style.display = 'block';
        this.captureBtn.style.display = 'inline-block';
        this.retakeBtn.style.display = 'none';
        this.useBtn.style.display = 'none';
        this.switchCameraBtn.style.display = 'inline-block';
        
        // Show placeholder
        const placeholder = document.getElementById('cameraPlaceholder');
        if (placeholder) placeholder.style.display = 'flex';
        
        this.startCamera();
    }
    
    async startCamera() {
        try {
            // Check if getUserMedia is supported
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                this.status.textContent = '❌ Camera not supported in this browser. Please use a modern browser.';
                return;
            }
            
            // Stop any existing stream
            if (this.stream) {
                this.stream.getTracks().forEach(track => track.stop());
                this.stream = null;
            }
            
            // Try to get camera with constraints
            const constraints = {
                video: {
                    facingMode: this.facingMode,
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                },
                audio: false
            };
            
            // For desktop, try to get any camera
            if (this.isDesktop) {
                constraints.video = true;
                this.status.textContent = '📷 Accessing webcam...';
            }
            
            this.stream = await navigator.mediaDevices.getUserMedia(constraints);
            
            // Hide placeholder
            const placeholder = document.getElementById('cameraPlaceholder');
            if (placeholder) placeholder.style.display = 'none';
            
            this.video.srcObject = this.stream;
            await this.video.play();
            
            // Adjust video size
            this.adjustVideoSize();
            
            this.status.textContent = '📷 Camera ready. Click "Capture" to take photo.';
        } catch (error) {
            console.error('Camera error:', error);
            let errorMsg = '❌ Camera access denied. ';
            
            if (error.name === 'NotAllowedError') {
                errorMsg += 'Please allow camera permission in your browser.';
            } else if (error.name === 'NotFoundError') {
                errorMsg += 'No camera found on this device.';
            } else if (error.name === 'OverconstrainedError') {
                errorMsg += 'Camera constraints not supported. Trying default...';
                // Retry with default constraints
                try {
                    this.stream = await navigator.mediaDevices.getUserMedia({ video: true });
                    this.video.srcObject = this.stream;
                    await this.video.play();
                    this.status.textContent = '📷 Camera ready. Click "Capture" to take photo.';
                    return;
                } catch (e) {
                    errorMsg = '❌ No camera available.';
                }
            } else {
                errorMsg += 'Please check your camera connection.';
            }
            
            this.status.textContent = errorMsg;
            const placeholder = document.getElementById('cameraPlaceholder');
            if (placeholder) {
                placeholder.style.display = 'flex';
                placeholder.textContent = '❌ Camera not available';
            }
        }
    }
    
    adjustVideoSize() {
        if (this.video) {
            const container = document.getElementById('cameraContainer');
            if (container) {
                const maxHeight = window.innerHeight * 0.6;
                this.video.style.maxHeight = Math.min(maxHeight, 450) + 'px';
            }
        }
    }
    
    async switchCamera() {
        this.facingMode = this.facingMode === 'user' ? 'environment' : 'user';
        this.status.textContent = '🔄 Switching camera...';
        await this.startCamera();
    }
    
    capture() {
        if (!this.stream) {
            this.status.textContent = '❌ Camera not available. Please try again.';
            return;
        }
        
        try {
            const context = this.canvas.getContext('2d');
            this.canvas.width = this.video.videoWidth || 640;
            this.canvas.height = this.video.videoHeight || 480;
            context.drawImage(this.video, 0, 0, this.canvas.width, this.canvas.height);
            
            this.capturedData = this.canvas.toDataURL('image/jpeg', 0.95);
            this.image.src = this.capturedData;
            this.image.style.display = 'block';
            this.video.style.display = 'none';
            this.captureBtn.style.display = 'none';
            this.retakeBtn.style.display = 'inline-block';
            this.useBtn.style.display = 'inline-block';
            this.switchCameraBtn.style.display = 'none';
            this.status.textContent = '✅ Photo captured! Click "Use Photo" to save or "Retake" to try again.';
        } catch (error) {
            console.error('Capture error:', error);
            this.status.textContent = '❌ Failed to capture photo. Please try again.';
        }
    }
    
    retake() {
        this.capturedData = null;
        this.image.style.display = 'none';
        this.video.style.display = 'block';
        this.captureBtn.style.display = 'inline-block';
        this.retakeBtn.style.display = 'none';
        this.useBtn.style.display = 'none';
        this.switchCameraBtn.style.display = 'inline-block';
        this.status.textContent = '📷 Ready to capture again.';
        this.adjustVideoSize();
    }
    
    usePhoto() {
        if (this.capturedData && this.callback) {
            this.callback(this.capturedData);
            this.close();
        } else {
            this.status.textContent = '❌ No photo captured. Please capture first.';
        }
    }
    
    close() {
        if (this.stream) {
            this.stream.getTracks().forEach(track => track.stop());
            this.stream = null;
        }
        this.modal.style.display = 'none';
        if (this.video) {
            this.video.pause();
            this.video.srcObject = null;
        }
        // Reset UI
        const placeholder = document.getElementById('cameraPlaceholder');
        if (placeholder) {
            placeholder.style.display = 'flex';
            placeholder.textContent = '🎥 Camera closed';
        }
    }
}

// Global camera instance
let cameraInstance = null;

function initCamera() {
    if (!cameraInstance) {
        cameraInstance = new CameraCapture();
    }
    return cameraInstance;
}

function capturePhoto(callback, type = 'photo') {
    const camera = initCamera();
    camera.open(callback, type);
}