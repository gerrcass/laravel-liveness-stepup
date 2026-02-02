import React, { useState, useCallback } from 'react';
import { FaceLivenessDetectorCore } from '@aws-amplify/ui-react-liveness';

const FaceLivenessDetector = ({ purpose = 'verification', onComplete, onError }) => {
    const [sessionId, setSessionId] = useState(null);
    const [credentials, setCredentials] = useState(null);
    const [region, setRegion] = useState(null);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState(null);

    const createSession = useCallback(async () => {
        setIsLoading(true);
        setError(null);

        try {
            let endpoint;
            if (purpose === 'registration') {
                endpoint = '/api/rekognition/create-face-liveness-session-registration';
            } else {
                endpoint = '/rekognition/create-face-liveness-session';
            }

            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                },
                body: JSON.stringify({ purpose }),
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();
            setSessionId(data.sessionId);
            setCredentials(data.credentials);
            setRegion(data.region);
        } catch (err) {
            setError(err.message);
            onError?.(err);
        } finally {
            setIsLoading(false);
        }
    }, [purpose, onError]);

    const handleAnalysisComplete = useCallback(async () => {
        if (!sessionId) return;

        try {
            let endpoint;
            if (purpose === 'registration') {
                endpoint = '/api/rekognition/complete-liveness-registration-guest';
            } else {
                endpoint = '/rekognition/complete-liveness-verification';
            }

            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                },
                body: JSON.stringify({ sessionId }),
            });

            const result = await response.json();
            
            // Add sessionId to result for registration
            if (purpose === 'registration' && result.success) {
                result.sessionId = sessionId;
            }
            
            onComplete?.(result);
        } catch (err) {
            onError?.(err);
        }
    }, [sessionId, purpose, onComplete, onError]);

    const handleError = useCallback((err) => {
        setError(err.message || 'Face Liveness error occurred');
        onError?.(err);
    }, [onError]);

    // Custom credentials provider for FaceLivenessDetectorCore
    const credentialProvider = useCallback(async () => {
        if (!credentials) {
            throw new Error('No credentials available');
        }
        
        return {
            accessKeyId: credentials.accessKeyId,
            secretAccessKey: credentials.secretAccessKey,
            sessionToken: credentials.sessionToken,
        };
    }, [credentials]);

    if (error) {
        return (
            <div style={{ color: 'red', padding: '1rem', border: '1px solid red', borderRadius: '4px' }}>
                <h3>Error</h3>
                <p>{error}</p>
                <button onClick={createSession} disabled={isLoading}>
                    Try Again
                </button>
            </div>
        );
    }

    if (!sessionId) {
        return (
            <div style={{ textAlign: 'center', padding: '2rem' }}>
                <h3>Face Liveness {purpose === 'registration' ? 'Registration' : 'Verification'}</h3>
                <p>Click the button below to start the Face Liveness check.</p>
                <button 
                    onClick={createSession} 
                    disabled={isLoading}
                    style={{
                        padding: '12px 24px',
                        fontSize: '16px',
                        backgroundColor: '#007bff',
                        color: 'white',
                        border: 'none',
                        borderRadius: '4px',
                        cursor: isLoading ? 'not-allowed' : 'pointer',
                    }}
                >
                    {isLoading ? 'Creating Session...' : 'Start Face Liveness Check'}
                </button>
            </div>
        );
    }

    return (
        <div style={{ width: '100%', maxWidth: '600px', margin: '0 auto' }}>
            <FaceLivenessDetectorCore
                sessionId={sessionId}
                region={region}
                onAnalysisComplete={handleAnalysisComplete}
                onError={handleError}
                config={{ credentialProvider }}
            />
        </div>
    );
};

export default FaceLivenessDetector;