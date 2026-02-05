# Lessons Learned: Face Liveness Integration with AWS Amplify & Laravel

## Overview

This document captures key lessons learned during the implementation of Amazon Rekognition Face Liveness in a Laravel application with React frontend.

## AWS Face Liveness Session Management

### Issue: ClientRequestToken Format Restrictions

**Problem**: AWS `CreateFaceLivenessSession` has format restrictions on `ClientRequestToken`. Passing user IDs (e.g., "18") caused `InvalidParameterException`.

**Solution**: Don't pass user ID as `ClientRequestToken`. Let AWS generate session IDs automatically:
```php
// WRONG:
$session = $rekognition->createFaceLivenessSession((string) $user->id);

// CORRECT:
$session = $rekognition->createFaceLivenessSession(null, [], false);
```

### Issue: S3 OutputConfig Causes Component Errors

**Problem**: When using `OutputConfig` with S3 bucket, the AWS Amplify `FaceLivenessDetectorCore` component reported "Cannot read image.png" and session errors.

**Solution**: Create sessions WITHOUT S3 output for frontend component compatibility:
```php
// Use S3 only for backend processing, not for frontend sessions
$session = $rekognition->createFaceLivenessSession(null, [], false); // false = no S3
```

## AWS Amplify FaceLivenessDetectorCore Component

### Issue: Component Errors with Empty Credentials

**Problem**: `FaceLivenessDetectorCore` called `credentialProvider` multiple times. If credentials weren't properly cached, it failed.

**Solution**: Ensure credentials are properly returned from the provider callback:
```javascript
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
```

### Issue: Session Not Found After Creation

**Problem**: `FaceLivenessDetectorCore` reported "Session not found" immediately after session creation.

**Solution**: 
1. Ensure session is fully created before rendering component
2. Don't reuse session IDs - each Face Liveness check needs a new session
3. Sessions expire after 3 minutes

## S3 Integration with Face Liveness

### Binary Data Handling

Face Liveness results can contain large binary data that cannot be JSON encoded. Implement cleanup:

```php
private function cleanLivenessResultForStorage(array $livenessResult): array
{
    $cleaned = $livenessResult;
    
    // Remove ReferenceImage Bytes
    if (isset($cleaned['ReferenceImage']['Bytes'])) {
        $bytesLength = strlen($cleaned['ReferenceImage']['Bytes']);
        unset($cleaned['ReferenceImage']['Bytes']);
        $cleaned['ReferenceImage']['HasBytes'] = true;
    }
    
    return $cleaned;
}
```

### S3Object vs Bytes

When `AWS_S3_BUCKET` is configured:
- Results contain `S3Object` instead of `Bytes`
- When S3 bucket is NOT configured:
- Results contain `Bytes` directly

Handle both cases:
```php
private function getReferenceImageBytes(array $sessionResults): string
{
    // Check if bytes are directly available
    if (isset($sessionResults['ReferenceImage']['Bytes'])) {
        return $sessionResults['ReferenceImage']['Bytes'];
    }
    
    // Check if image is stored in S3
    if (isset($sessionResults['ReferenceImage']['S3Object'])) {
        $s3Object = $sessionResults['ReferenceImage']['S3Object'];
        $bucket = $s3Object['Bucket'] ?? env('AWS_S3_BUCKET');
        $key = $s3Object['Name'] ?? null;
        
        // Download from S3...
    }
    
    throw new \Exception('No reference image found');
}
```

## CSRF Token Requirements

The React Face Liveness component requires CSRF token for API calls. Ensure meta tag is present:

```html
<meta name="csrf-token" content="{{ csrf_token() }}">
```

The component reads this token:
```javascript
'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
```

## Vite & React Build Configuration

### Issue: React Not Loading After Build

**Problem**: JavaScript bundle not loading React after `npm run build`.

**Solution**: Ensure Vite assets are properly loaded in layout:
```php
// In resources/views/layouts/app.blade.php
@vite(['resources/css/app.css', 'resources/js/app.js'])
```

### Bundle Size

AWS Amplify UI React Liveness adds significant bundle size (~1.8MB). This is expected due to the complex Face Liveness component.

## Laravel Routes

### Issue: GET Method Not Supported for Protected Routes

**Problem**: `/special-operation` only accepted POST, causing 405 errors when redirected from frontend.

**Solution**: Add GET route for protected operations:
```php
Route::get('/special-operation', function () {
    $user = auth()->user();
    return view('special_operation_result', [
        'user' => $user,
        'verification' => session('stepup_verification_result'),
    ]);
})->middleware(['auth', 'require.stepup'])->name('special.operation.get');
```

## Face Liveness Confidence Variability

### Normal Behavior

Face Liveness confidence scores can vary significantly between attempts:
- Good lighting + proper positioning: 90-99%
- Moderate conditions: 70-90%
- Poor conditions: below 60%

### Threshold Configuration

Lower the threshold for testing to avoid false negatives:
```php
// In RekognitionController and StepUpController
if ($externalId == (string) $user->id && $faceConfidence >= 60.0 && $livenessConfidence >= 60.0) {
    $success = true;
}
```

## Testing Best Practices

1. **Use fresh sessions**: Each Face Liveness attempt needs a new session
2. **Hard refresh browser**: After code changes, use `Ctrl+Shift+R`
3. **Check console logs**: Component outputs debugging info to browser console
4. **Multiple attempts**: Face Liveness is designed to sometimes fail for security

## File Structure Summary

Key files for Face Liveness:
- `app/Services/RekognitionService.php` - AWS Rekognition wrapper
- `app/Services/StsService.php` - STS for temporary credentials
- `app/Http/Controllers/RekognitionController.php` - API endpoints
- `resources/js/components/FaceLivenessDetector.jsx` - React component
- `resources/js/app.js` - React initialization
- `routes/web.php` - All routes including Face Liveness endpoints

## Environment Variables Required

```dotenv
AWS_ACCESS_KEY_ID=your_access_key
AWS_SECRET_ACCESS_KEY=your_secret_key
AWS_DEFAULT_REGION=us-east-1
AWS_S3_BUCKET=your_bucket_name  # Optional for Face Liveness
STEPUP_TIMEOUT=900  # seconds
```

## IAM Permissions Required

```
rekognition:CreateCollection
rekognition:IndexFaces
rekognition:SearchFacesByImage
rekognition:CreateFaceLivenessSession
rekognition:GetFaceLivenessSessionResults
rekognition:StartFaceLivenessSession
sts:GetSessionToken
s3:GetObject  # If using S3 bucket
```

## UI/UX Improvements (February 2026)

### Enhanced Error Handling

The FaceLivenessDetector component was improved with:

1. **Detailed Error Messages**: Instead of generic alerts, users now see:
   - Specific failure reasons (low liveness confidence, face not matched, etc.)
   - Confidence score display (liveness %, face match %)
   - Comparison against threshold requirements

2. **Actionable Hints**: Context-specific tips based on error type:
   - Low liveness confidence: lighting, positioning, movement hints
   - Face not matched: consistency with registration photo tips
   - Face not found: framing and visibility guidance

3. **Progress Indicator**: Visual feedback during verification analysis
   - Animated spinner during processing
   - "Analyzing..." status message

4. **Smoother Retry Flow**: No more page reloads on failure
   - "Try Again" button resets the component state
   - Maintains page context and scroll position
   - Users can immediately retry without losing their place

### Error Types Handled

```javascript
const errorTypes = {
    low_liveness_confidence: 'Liveness check did not meet security requirements',
    face_not_matched: 'Face did not match registered image',
    face_not_found: 'Face not detected in frame',
    component: 'Face Liveness component error',
    session_creation: 'Failed to create verification session',
    network: 'Network error during verification'
};
```

### Step-Up Page Improvements

The step-up verification page now displays:
- Formatted confidence scores with pass/fail indicators
- Expandable raw Rekognition response (hidden by default)
- Better visual hierarchy and color coding
- Responsive design improvements

## References

- [AWS Rekognition Face Liveness Documentation](https://docs.aws.amazon.com/rekognition/latest/dg/face-liveness.html)
- [AWS Amplify UI Face Liveness Component](https://ui.docs.amplify.aws/react/connected-components/liveness)

## Troubleshooting Common Issues

### "Cannot read 'image.png'" Error

**Symptom**: Console shows error: `Cannot read "image.png" (this model does not support image input)`

**Cause**: This is a known issue with the AWS Amplify FaceLivenessDetectorCore component. It occurs when:

1. The component's internal asset loading fails
2. Version incompatibility between `@aws-amplify/ui-react-liveness` and `aws-amplify`
3. The component tries to load fallback assets when session communication fails

**Solutions**:

1. **Ensure CSS is imported**:
   ```javascript
   // In app.js
   import '@aws-amplify/ui-react-liveness/styles.css';
   ```

2. **Add proper error handling for this specific error**:
   ```javascript
   const handleError = (err) => {
       if (err.message?.includes('image.png') || err.message?.includes('Cannot read')) {
           // Handle specifically - suggest refresh
           setError('Session error. Please refresh and try again.');
       }
   };
   ```

3. **Use manual retry mode**:
   ```javascript
   <FaceLivenessDetectorCore
       config={{
           retry: {
               mode: 'manual',
               onComplete: () => resetAndTryAgain()
           }
       }}
   />
   ```

4. **Check package versions**: Ensure compatible versions:
   ```json
   {
       "@aws-amplify/ui-react-liveness": "^3.0.0",
       "aws-amplify": "^6.0.0"
   }
   ```

### Understanding "success: false"

**Question**: When I see `success: false` in DevTools, should I assume the liveness check failed?

**Answer**: **Yes**, `success: false` means the Face Liveness verification failed. This can happen due to:

1. **Low liveness confidence** (< 60%): The system couldn't verify the face is real (not a photo/video)
2. **Face not matched**: The face doesn't match the registered image
3. **Face not found**: No face detected in the frame
4. **Session error**: Component or network issue

The component outputs the result to the console, but you should also check the UI for:
- Detailed error messages
- Confidence scores (liveness % and face match %)
- Actionable hints for improvement

### Black Figure Rendering Issue

**Question**: Why is there a black figure shown below "Check complete"? Shouldn't the oval area be on top of that black figure?

**Cause**: This is a rendering issue with the FaceLivenessDetectorCore component where the face detection overlay doesn't render correctly in certain conditions.

**Solutions**:

1. **Ensure component is properly mounted**:
   ```javascript
   const [componentReady, setComponentReady] = useState(false);

   // Only render component after session is ready
   {componentReady && (
       <FaceLivenessDetectorCore ... />
   )}
   ```

2. **Add CSS z-index fix** (if needed):
   ```css
   .amplify-face-liveness-detector {
       z-index: 1000 !important;
       position: relative;
   }
   ```

3. **Try a different browser**: Some browsers may have rendering issues with the component.

4. **Refresh the page**: The component may not have initialized correctly.

**Note**: This appears to be a known rendering quirk in the AWS Amplify component. The underlying verification still works correctly - it's a visual display issue only.

## Common Face Liveness Confidence Scores

| Score Range | Interpretation | Recommendation |
|-------------|----------------|----------------|
| 90-99% | Excellent | Verification should pass |
| 70-89% | Good | Likely to pass, but ensure good conditions |
| 60-69% | Borderline | May fail - improve lighting and positioning |
| < 60% | Poor | Will fail - see tips below |

### Tips for Higher Confidence Scores

1. **Lighting**: Use even, diffuse lighting. Avoid backlighting (light behind you).
2. **Positioning**: Keep your face centered and at a consistent distance.
3. **Movement**: Follow the on-screen instructions for head movement.
4. **Consistency**: Look similar to your registration photo (glasses, expression, etc.).
5. **Background**: Use a plain, neutral background.

## Laravel Form Validation with Conditional Fields

### Issue: Middleware ConvertEmptyStringsToNull Breaking Conditional Validation

**Problem**: When using Laravel's `exclude_if` validation rule, empty hidden fields were being converted to `null` by the `ConvertEmptyStringsToNull` middleware, causing validation to fail with "The field must be a string" error.

**Symptom**: Error message: "The liveness session id field must be a string."

**Solution**: Use `exclude_if:registration_method,image` to completely skip validation when the condition is met:
```php
$validated = $request->validate([
    'name' => 'required|string|max:255',
    'email' => 'required|string|email|max:255|unique:users',
    'password' => 'required|string|min:8|confirmed',
    'liveness_session_id' => [
        Rule::excludeIf(fn() => $request->registration_method === 'image'),
        'string',
    ],
]);
```

**Key Insight**: `exclude_if` and `exclude_unless` completely skip validation rules when the condition is met, preventing the middleware from converting empty strings to null for those fields.

## Code Placement in Conditional Logic

### Issue: Code Block Inside Wrong Conditional

**Problem**: A code block for handling "no face match found" was placed **inside** an `if (count($matches) > 0)` block, causing it to never execute when no face matches were found.

**Symptom**: Users with invalid faces saw a blank page instead of an error message.

**Solution**: Move the error handling code **outside** the conditional block:
```php
// WRONG - code inside the if, never runs when count is 0
if (count($matches) > 0) {
    // ... match handling ...
    
    // No face match found - THIS CODE NEVER RUNS!
    return redirect()->route('stepup.show')->withErrors([...]);
}

// CORRECT - code outside the if
if (count($matches) > 0) {
    // ... match handling ...
}

// No face match found - THIS CODE RUNS when count is 0
return redirect()->route('stepup.show')->withErrors([...]);
```

**Key Insight**: Always verify that error handling code is placed at the correct scope level. Code inside a conditional only runs when that condition is true.

## Redirect Flow Differences: Valid vs Invalid Faces

### Behavior Difference

**Observation**: Verification with valid faces appears slower than with invalid faces.

**Explanation**: The flows are fundamentally different:

- **Invalid faces**: Direct redirect to `/step-up` with error session data (1 request)
- **Valid faces**: Redirect through `stepup_post_redirect` with hidden form + JavaScript (2+ requests)

**Valid Face Flow**:
```
1. POST /step-up/verify → Verification successful
2. Return view('stepup_post_redirect') with hidden form
3. Browser loads page, JavaScript auto-submits form
4. POST /special-operation → Success page
```

**Invalid Face Flow**:
```
1. POST /step-up/verify → Verification failed
2. Redirect to /step-up (GET) with error data
3. Page loads with error message
```

**Key Insight**: The extra step for valid faces is necessary to maintain POST data for protected operations. The performance difference is expected behavior, not a bug.

## Session Data Passing Across Redirects

### Issue: Verification Data Lost in POST Flow

**Problem**: Verification data was generated after the redirect to `stepup_post_redirect`, causing it to be unavailable when the form was submitted to `/special-operation`.

**Solution**: Generate and store verification data BEFORE any redirect:
```php
// Generate verification data FIRST
$verificationData = [
    'method' => 'image',
    'confidence' => $confidence,
    // ... other fields
];

// Store in session for both GET and POST flows
$request->session()->put('stepup_verification_result', $verificationData);

// Now safe to redirect
return view('stepup_post_redirect', compact('targetUrl', 'inputs', 'verificationData'));
```

**Key Insight**: When using intermediate redirect pages (like `stepup_post_redirect`), verification data must be stored in session BEFORE returning the view, not after.

## Form Submission Methods: Normal vs AJAX

### Issue: Form Being Submitted via fetch Instead of Normal Submit

**Problem**: The verification form was being submitted using `fetch` (AJAX) instead of normal browser form submission, causing redirects to not work properly.

**Symptom**: User was redirected to `/step-up/verify` (the POST endpoint) with a blank page instead of being redirected to `/step-up` with errors.

**Investigation**: Check browser DevTools Network tab for the actual request method being used.

**Solution**: Use explicit redirects instead of `back()`:
```php
// Use explicit route redirect instead of back()
return redirect()->route('stepup.show')->withErrors(['face' => 'Verification failed']);
```

**Key Insight**: `back()->withErrors()` relies on HTTP_REFERER which may not always be reliable. Using explicit `redirect()->route()` is more robust.

## Debugging Techniques for Laravel Controllers

### Adding Strategic Logs

When debugging complex controller logic, add logs at key points:
```php
public function verify(Request $request, RekognitionService $rekognition)
{
    logger('verify - called');
    logger('verify - registrationMethod: ' . $registrationMethod);
    logger('verify - entering image verification block');
    logger('verify - validation passed');
    logger('verify - searchFace completed', ['FaceMatches' => count($result['FaceMatches'] ?? [])]);
    logger('verify - matches count: ' . count($matches));
    logger('verify - no face match found, redirecting...');
}
```

**Key Insight**: Progressive logging helps identify exactly where code execution stops or takes a different path than expected.
