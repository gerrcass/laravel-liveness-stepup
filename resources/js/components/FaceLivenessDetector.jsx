import React, { useState, useCallback, useEffect, useRef } from 'react';
import { FaceLivenessDetectorCore } from '@aws-amplify/ui-react-liveness';

const FaceLivenessDetector = ({ purpose = 'verification', onComplete, onError }) => {
    const [sessionId, setSessionId] = useState(null);
    const [credentials, setCredentials] = useState(null);
    const [region, setRegion] = useState(null);
    const [isLoading, setIsLoading] = useState(false);
    const [isVerifying, setIsVerifying] = useState(false);
    const [error, setError] = useState(null);
    const [errorDetails, setErrorDetails] = useState(null);
    const [showHints, setShowHints] = useState(false);
    const [lastResult, setLastResult] = useState(null);
    const [componentReady, setComponentReady] = useState(false);
    const mountRef = useRef(false);

    useEffect(() => {
        mountRef.current = true;
        return () => {
            mountRef.current = false;
        };
    }, []);

    const createSession = useCallback(async () => {
        if (!mountRef.current) return;

        setIsLoading(true);
        setError(null);
        setErrorDetails(null);
        setLastResult(null);
        setShowHints(false);
        setComponentReady(false);
        console.log('createSession called, purpose:', purpose);

        try {
            let endpoint;
            if (purpose === 'registration') {
                endpoint = '/api/rekognition/create-face-liveness-session-registration';
            } else {
                endpoint = '/rekognition/create-face-liveness-session';
            }
            console.log('Calling endpoint:', endpoint);

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
            console.log('Session created:', data.sessionId);
            console.log('Credentials received:', data.credentials ? 'yes' : 'no');

            if (!mountRef.current) return;

            setSessionId(data.sessionId);
            setCredentials(data.credentials);
            setRegion(data.region);
            setComponentReady(true);
        } catch (err) {
            console.error('createSession error:', err);
            if (mountRef.current) {
                setError(err.message);
                setErrorDetails({ type: 'session_creation', message: err.message });
                onError?.(err);
            }
        } finally {
            if (mountRef.current) {
                setIsLoading(false);
            }
        }
    }, [purpose, onError]);

    const handleAnalysisComplete = useCallback(async (analysisResult) => {
        console.log('handleAnalysisComplete called, sessionId:', sessionId);
        console.log('Analysis result:', analysisResult);

        if (!sessionId || !mountRef.current) {
            // Ensure isVerifying is reset even on early return
            if (mountRef.current) {
                setIsVerifying(false);
            }
            return;
        }

        setIsVerifying(true);
        setError(null);
        setErrorDetails(null);

        try {
            // Call the appropriate backend endpoint based on purpose
            // IMPORTANT: We must call this BEFORE the component's internal result retrieval
            const endpoint = purpose === 'registration' 
                ? '/api/rekognition/complete-liveness-registration-guest'
                : '/rekognition/complete-liveness-verification';
            console.log('Calling completion endpoint:', endpoint);

            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                },
                body: JSON.stringify({ sessionId }),
            });

            const result = await response.json();
            console.log('Completion result:', result);
            setLastResult(result);

            // Store session ID in hidden form field (for verification form submission)
            const sessionIdInput = document.getElementById('liveness_session_id');
            if (sessionIdInput) {
                sessionIdInput.value = sessionId;
            }
            
            // Mark liveness as completed (for form validation)
            if (typeof window.livenessCompleted !== 'undefined') {
                window.livenessCompleted = true;
            }

            if (result.success) {
                if (purpose === 'verification') {
                    // For verification, the backend already verified the face
                    // Just call onComplete to trigger redirect
                    onComplete?.(result);
                } else {
                    // For registration, the backend indexed the face
                    onComplete?.(result);
                }
            } else if (!result.success) {
                const errorType = getErrorType(result);
                setErrorDetails({
                    type: errorType,
                    message: result.message || 'Verification failed',
                    livenessConfidence: result.livenessConfidence,
                    faceConfidence: result.faceConfidence,
                    threshold: result.threshold || 60,
                    livenessResult: result.livenessResult,
                    searchResult: result.searchResult,
                    searchError: result.searchError,
                    referenceImageUrl: result.referenceImageUrl
                });

                if (errorType === 'low_liveness_confidence') {
                    setError('Liveness check did not meet security requirements');
                    setShowHints(true);
                } else if (errorType === 'face_not_matched') {
                    setError('Face did not match registered image');
                    setShowHints(true);
                } else if (errorType === 'face_not_found') {
                    setError('No faces detected in the image');
                    setShowHints(true);
                } else {
                    setError(result.message || 'Verification failed');
                    setShowHints(true);
                }
                onError?.(result);
            }
        } catch (err) {
            console.error('handleAnalysisComplete error:', err);
            if (mountRef.current) {
                setError(err.message);
                setErrorDetails({ type: 'network', message: err.message });
                onError?.(err);
            }
        } finally {
            if (mountRef.current) {
                setIsVerifying(false);
            }
        }
    }, [sessionId, purpose, onComplete, onError]);

    const getErrorType = (result) => {
        // Check for search error (no faces detected)
        if (result.searchError && result.searchError.includes('no faces')) {
            return 'face_not_found';
        }
        if (result.livenessConfidence !== undefined && result.livenessConfidence < (result.threshold || 60)) {
            return 'low_liveness_confidence';
        }
        if (result.faceConfidence !== undefined && result.faceConfidence < (result.threshold || 60)) {
            return 'face_not_matched';
        }
        if (result.error === 'face_not_found') {
            return 'face_not_found';
        }
        return 'unknown';
    };

    const handleError = useCallback((err) => {
        console.error('FaceLivenessDetectorCore error:', err);
        console.error('Error state:', err.state);
        console.error('Error details:', err);

        // If we already have a successful result from the backend, suppress this error completely
        // This happens because the component tries to get results after the callback completes,
        // but the backend already consumed them
        if (lastResult && lastResult.success) {
            console.log('Suppressing component error - backend already processed successfully');
            return; // Don't call onError, don't show error UI
        }

        const errorMessage = err.message || err.state || 'Face Liveness error occurred';

        if (errorMessage.includes('image.png') || errorMessage.includes('Cannot read')) {
            setError('Face Liveness session error. Please try again.');
            setErrorDetails({
                type: 'component_session',
                message: 'The component could not initialize the Face Liveness session properly.',
                rawError: errorMessage
            });
            setShowHints(true);
        } else {
            setError(errorMessage);
            setErrorDetails({
                type: 'component',
                message: errorMessage,
                rawError: err
            });
            setShowHints(true);
        }
        onError?.(err);
    }, [lastResult, onError]);

    const getHintsForError = (errorType) => {
        const hints = {
            low_liveness_confidence: [
                'Ensure your face is well-lit with even lighting',
                'Avoid strong backlighting or shadows on your face',
                'Keep your face centered in the frame',
                'Follow the on-screen instructions for movement',
                'Make sure your entire face is visible'
            ],
            face_not_matched: [
                'Ensure you look similar to your registration photo',
                'Remove glasses or hats if you wore them during registration',
                'Ensure consistent lighting between registration and verification',
                'Keep your face at a similar distance from camera'
            ],
            face_not_found: [
                'Position your face within the frame guidelines',
                'Ensure your face is clearly visible',
                'Avoid covering your face with hair or hands',
                'Make sure there is a face in front of the camera',
                'Try moving closer to the camera'
            ],
            component: [
                'Refresh the page and try again',
                'Ensure your camera is working and not blocked',
                'Check your internet connection'
            ],
            component_session: [
                'Refresh the page to create a new session',
                'Ensure your browser allows camera access',
                'Check your internet connection',
                'Try using a different browser'
            ],
            session_creation: [
                'Refresh the page and try again',
                'Check your internet connection'
            ],
            network: [
                'Check your internet connection',
                'Refresh the page and try again'
            ],
            unknown: [
                'Refresh the page and try again',
                'Ensure good lighting and camera access'
            ]
        };
        return hints[errorType] || hints.unknown;
    };

    const resetAndTryAgain = () => {
        setSessionId(null);
        setCredentials(null);
        setError(null);
        setErrorDetails(null);
        setLastResult(null);
        setShowHints(false);
        setComponentReady(false);
    };

    const credentialProvider = useCallback(async () => {
        console.log('credentialProvider called, credentials exist:', !!credentials);

        if (!credentials) {
            throw new Error('No credentials available');
        }

        if (!credentials.accessKeyId || !credentials.secretAccessKey || !credentials.sessionToken) {
            console.error('Invalid credentials structure:', credentials);
            throw new Error('Invalid credentials structure');
        }

        const creds = {
            accessKeyId: credentials.accessKeyId,
            secretAccessKey: credentials.secretAccessKey,
            sessionToken: credentials.sessionToken,
        };

        console.log('Returning credentials for:', credentials.accessKeyId);
        return creds;
    }, [credentials]);

    if (error) {
        const hints = errorDetails ? getHintsForError(errorDetails.type) : [];
        const timestamp = new Date().toLocaleString();
        const showReferenceImage = errorDetails?.referenceImageUrl;

        return (
            <div style={{
                padding: '2rem',
                border: '1px solid #f5c6cb',
                borderRadius: '8px',
                backgroundColor: '#fff5f5',
                // maxWidth: '600px',
                margin: '0 auto'
            }}>
                <h3 style={{ color: '#c82333', marginTop: 0 }}>❌ Verification Failed</h3>
                <p style={{ color: '#721c24', fontSize: '16px' }}>{error}</p>

                {errorDetails && (errorDetails.livenessConfidence !== undefined || errorDetails.faceConfidence !== undefined) && (
                    <div style={{
                        backgroundColor: '#fff',
                        padding: '1rem',
                        borderRadius: '4px',
                        margin: '1rem 0'
                    }}>
                        <h4 style={{ marginTop: 0 }}>Analysis Results</h4>
                        <p style={{ margin: '0.25rem 0', color: '#6c757d' }}>
                            <strong>Verified at:</strong> {timestamp}
                        </p>
                        {errorDetails.livenessConfidence !== undefined && (
                            <p style={{
                                color: errorDetails.livenessConfidence >= (errorDetails.threshold || 60) ? '#28a745' : '#dc3545'
                            }}>
                                Liveness Confidence: {errorDetails.livenessConfidence.toFixed(1)}%
                                <span style={{color: '#6c757d', fontSize: '0.9em'}}> ({(errorDetails.threshold || 60)}% required)</span>
                            </p>
                        )}
                        {errorDetails.faceConfidence !== undefined && (
                            <p style={{
                                color: errorDetails.faceConfidence >= (errorDetails.threshold || 60) ? '#28a745' : '#dc3545'
                            }}>
                                Face Match Confidence: {errorDetails.faceConfidence.toFixed(1)}%
                                <span style={{color: '#6c757d', fontSize: '0.9em'}}> ({(errorDetails.threshold || 60)}% required)</span>
                            </p>
                        )}
                    </div>
                )}

                {/* Raw API responses from AWS Rekognition */}
                {errorDetails?.livenessResult && (
                    <details style={{ marginTop: '1rem' }}>
                        <summary style={{ cursor: 'pointer', color: '#721c24', fontWeight: 'bold' }}>
                            GetFaceLivenessSessionResults (Face Liveness API)
                        </summary>
                        <pre style={{
                            background: '#fff',
                            padding: '0.75rem',
                            overflow: 'auto',
                            maxHeight: '300px',
                            marginTop: '0.5rem',
                            border: '1px solid #f5c6cb',
                            fontSize: '0.85rem'
                        }}>
                            {JSON.stringify(errorDetails.livenessResult, null, 2)}
                        </pre>
                    </details>
                )}

                {errorDetails?.searchResult && (
                    <details style={{ marginTop: '0.5rem' }}>
                        <summary style={{ cursor: 'pointer', color: '#721c24', fontWeight: 'bold' }}>
                            SearchFacesByImage (Face Recognition API)
                        </summary>
                        <pre style={{
                            background: '#fff',
                            padding: '0.75rem',
                            overflow: 'auto',
                            maxHeight: '300px',
                            marginTop: '0.5rem',
                            border: '1px solid #f5c6cb',
                            fontSize: '0.85rem'
                        }}>
                            {JSON.stringify(errorDetails.searchResult, null, 2)}
                        </pre>
                    </details>
                )}

                {/* Reference image from Face Liveness verification */}
                {showReferenceImage && (
                    <div style={{ marginTop: '1rem', paddingTop: '1rem', borderTop: '1px solid #f5c6cb' }}>
                        <p style={{ margin: '0 0 0.5rem 0', fontWeight: 'bold' }}>
                            Reference image from Face Liveness verification:
                        </p>
                        <img 
                            src={`${errorDetails.referenceImageUrl}?t=${Date.now()}`} 
                            alt="Reference image from verification"
                            style={{ maxWidth: '300px', maxHeight: '300px', border: '1px solid #f5c6cb', borderRadius: '4px' }}
                        />
                    </div>
                )}

                {showHints && hints.length > 0 && (
                    <div style={{ marginTop: '1rem' }}>
                        <h4 style={{ color: '#856404' }}>Tips to improve your verification:</h4>
                        <ul style={{ color: '#856404', paddingLeft: '1.5rem' }}>
                            {hints.map((hint, index) => (
                                <li key={index} style={{ marginBottom: '0.5rem' }}>{hint}</li>
                            ))}
                        </ul>
                    </div>
                )}

                <button
                    onClick={resetAndTryAgain}
                    disabled={isLoading}
                    style={{
                        padding: '12px 24px',
                        fontSize: '16px',
                        backgroundColor: '#007bff',
                        color: 'white',
                        border: 'none',
                        borderRadius: '4px',
                        cursor: isLoading ? 'not-allowed' : 'pointer',
                        marginTop: '1rem'
                    }}
                >
                    {isLoading ? 'Please wait...' : 'Try Again'}
                </button>
            </div>
        );
    }

    if (isVerifying) {
        return (
            <div style={{
                textAlign: 'center',
                padding: '3rem',
                backgroundColor: '#e3f2fd',
                borderRadius: '8px',
                maxWidth: '600px',
                margin: '0 auto'
            }}>
                <div style={{
                    width: '50px',
                    height: '50px',
                    border: '4px solid #2196f3',
                    borderTopColor: 'transparent',
                    borderRadius: '50%',
                    animation: 'spin 1s linear infinite',
                    margin: '0 auto 1rem'
                }}></div>
                <h3 style={{ color: '#1976d2' }}>Analyzing...</h3>
                <p>Please wait while we process your verification.</p>
                <style>{`
                    @keyframes spin {
                        to { transform: rotate(360deg); }
                    }
                `}</style>
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
            {componentReady && (
                <FaceLivenessDetectorCore
                    sessionId={sessionId}
                    region={region || 'us-east-1'}
                    onAnalysisComplete={handleAnalysisComplete}
                    onError={handleError}
                    config={{
                        credentialProvider,
                        retry: {
                            mode: 'manual',
                            onComplete: () => {
                                console.log('Manual retry triggered');
                                resetAndTryAgain();
                            }
                        }
                    }}
                />
            )}
        </div>
    );
};

export default FaceLivenessDetector;