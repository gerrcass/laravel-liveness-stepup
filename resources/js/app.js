import './bootstrap';
import React from 'react';
import { createRoot } from 'react-dom/client';
import { Amplify } from 'aws-amplify';
import '@aws-amplify/ui-react-liveness/styles.css';
import FaceLivenessDetector from './components/FaceLivenessDetector';

if (typeof window !== 'undefined') {
    window.Amplify = Amplify;
}

window.initializeFaceLiveness = function(purpose = 'verification', options = {}) {
    const container = document.getElementById('face-liveness-root');
    if (!container) {
        console.error('Face Liveness container not found');
        return;
    }

    const root = createRoot(container);
    
    // Get threshold from options or global window variable (set by Blade template)
    const threshold = options.threshold ?? window.REKOGNITION_CONFIDENCE_THRESHOLD ?? 85.0;
    console.log('FaceLivenessDetector initialized with threshold:', threshold);

    const handleComplete = (result) => {
        console.log('Face Liveness completed:', result);

        if (purpose === 'registration') {
            if (result.success && result.sessionId) {
                document.getElementById('liveness_session_id').value = result.sessionId;
            }

            if (window.onLivenessComplete) {
                window.onLivenessComplete(result);
            }
        } else {
            if (window.onLivenessComplete) {
                window.onLivenessComplete(result);
            }
        }
    };

    const handleError = (error) => {
        console.error('Face Liveness error:', error);

        if (window.onLivenessError) {
            window.onLivenessError(error);
        }
    };

    root.render(
        React.createElement(FaceLivenessDetector, {
            purpose: purpose,
            onComplete: handleComplete,
            onError: handleError,
            threshold: threshold
        })
    );
};

// Modal-specific Face Liveness initialization
window.loadModalFaceLivenessComponent = function() {
    const container = document.getElementById('modal-face-liveness-root');
    if (!container) {
        console.error('Modal Face Liveness container not found');
        return;
    }

    const root = createRoot(container);
    
    const threshold = window.REKOGNITION_CONFIDENCE_THRESHOLD ?? 85.0;
    console.log('Modal FaceLivenessDetector initialized with threshold:', threshold);

    const handleComplete = (result) => {
        console.log('Modal Face Liveness completed:', result);

        livenessCompleted = true;
        const sessionInput = document.getElementById('modal_liveness_session_id');
        const submitBtn = document.getElementById('modal-submit-btn');
        
        if (sessionInput && result.success && result.sessionId) {
            sessionInput.value = result.sessionId;
        }
        if (submitBtn) {
            submitBtn.disabled = false;
        }

        if (window.onLivenessComplete) {
            window.onLivenessComplete(result);
        }
    };

    const handleError = (error) => {
        console.error('Modal Face Liveness error:', error);

        if (window.onLivenessError) {
            window.onLivenessError(error);
        }
    };

    root.render(
        React.createElement(FaceLivenessDetector, {
            purpose: 'registration',
            onComplete: handleComplete,
            onError: handleError,
            threshold: threshold
        })
    );
};
