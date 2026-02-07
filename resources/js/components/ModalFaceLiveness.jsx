import React, { useState, useCallback, useEffect, useRef } from 'react';
import { FaceLivenessDetectorCore } from '@aws-amplify/ui-react-liveness';

/**
 * ModalFaceLiveness Component
 * 
 * A clean wrapper for AWS Amplify FaceLivenessDetectorCore that provides
 * a seamless modal experience without popup artifacts.
 * 
 * This component handles the race condition between the component's internal
 * result processing and our backend by immediately replacing the UI
 * upon successful completion.
 */
const ModalFaceLiveness = ({ 
    purpose = 'registration',
    onComplete, 
    onError, 
    threshold = 85.0 
}) => {
    const [sessionId, setSessionId] = useState(null);
    const [credentials, setCredentials] = useState(null);
    const [region, setRegion] = useState(null);
    const [isLoading, setIsLoading] = useState(false);
    const [isAnalyzing, setIsAnalyzing] = useState(false);
    const [isComplete, setIsComplete] = useState(false);
    const [completionResult, setCompletionResult] = useState(null);
    const [error, setError] = useState(null);
    const mountRef = useRef(false);
    const containerRef = useRef(null);
    const cleanupDoneRef = useRef(false);

    useEffect(() => {
        mountRef.current = true;
        return () => {
            mountRef.current = false;
        };
    }, []);

    const cleanupAmplifyUI = useCallback(() => {
        if (cleanupDoneRef.current) return;
        cleanupDoneRef.current = true;

        // Immediately hide/remove any AWS Amplify UI elements
        setTimeout(() => {
            if (containerRef.current) {
                const container = containerRef.current;
                container.style.opacity = '0';
                container.style.pointerEvents = 'none';
                
                // Remove all amplify-related elements
                const amplifyElements = container.querySelectorAll(
                    '.amplify-face-liveness-detector, .amplify-modal, [class*="amplify-liveness"], [class*="amplify-face"]'
                );
                amplifyElements.forEach(el => el.remove());
            }

            // Also clean body-level overlays
            const bodyOverlays = document.querySelectorAll('.amplify-modal__overlay, .amplify-overlay, [class*="amplify"][class*="overlay"]');
            bodyOverlays.forEach(el => el.remove());
        }, 50);
    }, []);

    const createSession = useCallback(async () => {
        if (!mountRef.current) return;

        setIsLoading(true);
        setError(null);
        setIsComplete(false);
        cleanupDoneRef.current = false;

        try {
            const endpoint = purpose === 'registration'
                ? '/rekognition/create-face-liveness-session-registration'
                : '/rekognition/create-face-liveness-session';

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
            
            if (!mountRef.current) return;

            setSessionId(data.sessionId);
            setCredentials(data.credentials);
            setRegion(data.region);
        } catch (err) {
            console.error('createSession error:', err);
            if (mountRef.current) {
                setError(err.message);
                onError?.(err);
            }
        } finally {
            if (mountRef.current) {
                setIsLoading(false);
            }
        }
    }, [purpose, onError]);

    const handleAnalysisComplete = useCallback(async (analysisResult) => {
        console.log('ModalFaceLiveness - Analysis complete:', analysisResult);
        
        if (!sessionId || !mountRef.current) return;

        setIsAnalyzing(true);

        try {
            const endpoint = purpose === 'registration'
                ? '/rekognition/complete-liveness-registration-guest'
                : '/rekognition/complete-liveness-verification';

            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                },
                body: JSON.stringify({ sessionId }),
            });

            const result = await response.json();
            console.log('ModalFaceLiveness - Backend result:', result);

            if (!mountRef.current) return;

            if (result.success) {
                // Success - immediately cleanup and show our success UI
                cleanupAmplifyUI();
                setCompletionResult(result);
                setIsComplete(true);
                onComplete?.(result);
            } else {
                // Backend returned error - cleanup and show error
                cleanupAmplifyUI();
                setError(result.message || 'Verification failed');
                onError?.(result);
            }
        } catch (err) {
            console.error('ModalFaceLiveness - Handle error:', err);
            if (mountRef.current) {
                cleanupAmplifyUI();
                setError(err.message);
                onError?.(err);
            }
        } finally {
            if (mountRef.current) {
                setIsAnalyzing(false);
            }
        }
    }, [sessionId, purpose, onComplete, onError, cleanupAmplifyUI]);

    const handleError = useCallback((err) => {
        console.error('ModalFaceLiveness - Component error:', err);
        
        // Suppress "results available" errors if backend will handle them
        const errorMessage = err?.message || err?.state || '';
        if (errorMessage.includes('results available') || errorMessage.includes('No such session')) {
            console.log('Suppressing race condition error - backend will handle');
            return;
        }

        if (mountRef.current) {
            cleanupAmplifyUI();
            setError(err.message || 'Face Liveness error occurred');
            onError?.(err);
        }
    }, [onError, cleanupAmplifyUI]);

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

    // const handleContinue = () => {
    //     // Just close/continue - the form will handle the rest
    //     onComplete?.(completionResult);
    // };

    // Show success UI
    if (isComplete) {
        return (
            <div style={{
                textAlign: 'center',
                padding: '2rem',
                backgroundColor: '#d4edda',
                borderRadius: '8px',
                border: '1px solid #c3e6cb'
            }}>
                <div style={{
                    width: '60px',
                    height: '60px',
                    backgroundColor: '#28a745',
                    borderRadius: '50%',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    margin: '0 auto 1rem',
                    fontSize: '30px',
                    color: 'white'
                }}>✓</div>
                <h3 style={{ color: '#155724', marginTop: 0 }}>Face Liveness Verificado</h3>
                <p style={{ color: '#155724' }}>
                    Tu identidad ha sido verificada exitosamente.
                </p>
                {completionResult?.confidence && (
                    <p style={{ color: '#155724', fontSize: '14px' }}>
                        Confianza: {completionResult.confidence.toFixed(1)}%
                    </p>
                )}
                {/* <button
                    onClick={handleContinue}
                    style={{
                        padding: '10px 24px',
                        fontSize: '16px',
                        backgroundColor: '#28a745',
                        color: 'white',
                        border: 'none',
                        borderRadius: '4px',
                        cursor: 'pointer',
                        marginTop: '1rem'
                    }}
                >
                    Continuar
                </button> */}
            </div>
        );
    }

    // Show error UI
    if (error) {
        return (
            <div style={{
                textAlign: 'center',
                padding: '2rem',
                backgroundColor: '#f8d7da',
                borderRadius: '8px',
                border: '1px solid #f5c6cb'
            }}>
                <div style={{
                    width: '60px',
                    height: '60px',
                    backgroundColor: '#dc3545',
                    borderRadius: '50%',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    margin: '0 auto 1rem',
                    fontSize: '30px',
                    color: 'white'
                }}>✕</div>
                <h3 style={{ color: '#721c24', marginTop: 0 }}>Verificación Fallida</h3>
                <p style={{ color: '#721c24' }}>{error}</p>
                <button
                    onClick={() => {
                        setError(null);
                        setSessionId(null);
                        cleanupDoneRef.current = false;
                    }}
                    style={{
                        padding: '10px 24px',
                        fontSize: '16px',
                        backgroundColor: '#007bff',
                        color: 'white',
                        border: 'none',
                        borderRadius: '4px',
                        cursor: 'pointer',
                        marginTop: '1rem'
                    }}
                >
                    Intentar de Nuevo
                </button>
            </div>
        );
    }

    // Show analyzing UI
    if (isAnalyzing) {
        return (
            <div style={{
                textAlign: 'center',
                padding: '3rem',
                backgroundColor: '#e3f2fd',
                borderRadius: '8px'
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
                <h3 style={{ color: '#1976d2', marginTop: 0 }}>Verificando...</h3>
                <p>Por favor espera mientras procesamos tu verificación.</p>
                <style>{`
                    @keyframes spin {
                        to { transform: rotate(360deg); }
                    }
                `}</style>
            </div>
        );
    }

    // Show FaceLivenessDetectorCore when session is ready
    if (sessionId) {
        return (
            <div ref={containerRef} style={{ width: '100%', maxWidth: '500px', margin: '0 auto' }}>
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
                            }
                        }
                    }}
                />
            </div>
        );
    }

    // Show initial state
    return (
        <div style={{
            textAlign: 'center',
            padding: '2rem'
        }}>
            <h3 style={{ marginTop: 0 }}>Verificación Facial</h3>
            <p>Completa el desafío de Face Liveness para verificar tu identidad.</p>
            <button
                onClick={createSession}
                disabled={isLoading}
                style={{
                    padding: '12px 24px',
                    fontSize: '16px',
                    // backgroundColor: '#007bff',
                    backgroundColor: '#f0f7ff',
                    color: '#007bff',
                    // border: 'none',
                    borderWidth: '1px',
                    borderColor: '#007bff',
                    borderRadius: '32px',
                    cursor: isLoading ? 'not-allowed' : 'pointer',
                    opacity: isLoading ? 0.7 : 1
                }}
            >
                {isLoading ? 'Iniciando...' : 'Iniciar Verificación'}
            </button>
        </div>
    );
};

export default ModalFaceLiveness;
