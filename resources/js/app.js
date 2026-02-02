import './bootstrap';
import React from 'react';
import { createRoot } from 'react-dom/client';
import FaceLivenessDetector from './components/FaceLivenessDetector';

// Global function to initialize Face Liveness component
window.initializeFaceLiveness = function(purpose = 'verification') {
    const container = document.getElementById('face-liveness-root');
    if (!container) {
        console.error('Face Liveness container not found');
        return;
    }

    const root = createRoot(container);
    
    const handleComplete = (result) => {
        console.log('Face Liveness completed:', result);
        
        if (purpose === 'registration') {
            // Store session ID for registration form
            if (result.success && result.sessionId) {
                document.getElementById('liveness_session_id').value = result.sessionId;
            }
            
            // Call global callback if available
            if (window.onLivenessComplete) {
                window.onLivenessComplete(result);
            }
        } else {
            // For verification, call global callback
            if (window.onLivenessComplete) {
                window.onLivenessComplete(result);
            }
        }
    };

    const handleError = (error) => {
        console.error('Face Liveness error:', error);
        
        // Call global error callback if available
        if (window.onLivenessError) {
            window.onLivenessError(error);
        }
    };

    root.render(
        React.createElement(FaceLivenessDetector, {
            purpose: purpose,
            onComplete: handleComplete,
            onError: handleError
        })
    );
};
