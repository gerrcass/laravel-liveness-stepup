# Amazon Rekognition Step-Up Authentication with Face Recognition & Face Liveness - Laravel Demo

This project is a proof-of-concept demonstrating how to implement **step-up authentication** in a Laravel application using **Amazon Rekognition's Face Recognition** and **Face Liveness** features. This allows you to require an additional, high-assurance verification step for users attempting to access sensitive parts of your application.

Users can register with either a reference face image or Face Liveness, and subsequent verifications use the corresponding method.

## Table of Contents

- [Amazon Rekognition Step-Up Authentication with Face Recognition \& Face Liveness - Laravel Demo](#amazon-rekognition-step-up-authentication-with-face-recognition--face-liveness---laravel-demo)
  - [Table of Contents](#table-of-contents)
  - [Project Overview](#project-overview)
  - [How it Works](#how-it-works)
    - [Registration Methods](#registration-methods)
    - [Verification Methods](#verification-methods)
  - [Core Technologies](#core-technologies)
  - [Setup and Installation](#setup-and-installation)
  - [Configuration](#configuration)
    - [AWS Credentials](#aws-credentials)
    - [Face Liveness Configuration](#face-liveness-configuration)
    - [Rekognition Configuration](#rekognition-configuration)
  - [Running the Application](#running-the-application)
  - [Key Application Flow](#key-application-flow)
  - [Database Schema - user\_faces Table](#database-schema---user_faces-table)
  - [Project Structure](#project-structure)

## Project Overview

In many applications, certain actions (e.g., changing account details, transferring funds, accessing admin panels) are more sensitive than others. Standard password-based authentication might not be sufficient to protect these actions. Step-up authentication provides an additional layer of security by requiring the user to re-verify their identity in real-time.

This project now supports two methods of face verification:
1. **Traditional Face Recognition**: Users upload a reference image during registration and verify with uploaded images
2. **Face Liveness**: Users complete a video selfie challenge during registration and verification, providing protection against spoofing attacks

## How it Works

### Registration Methods

**Traditional Image Method:**
1. User registers with username, password, and uploads a reference face image
2. The face image is indexed in a Rekognition face collection with the user's ID as the external identifier

**Face Liveness Method:**
1. User registers with username, password, and completes a Face Liveness challenge
2. The system creates a Face Liveness session and captures a reference image from the video
3. The reference image is indexed in the Rekognition face collection

### Verification Methods

**Traditional Image Verification:**
1. User uploads a live image through a form
2. Backend uses Rekognition's `SearchFacesByImage` API to compare against the collection
3. Verification succeeds if similarity confidence >= 60% and user ID matches
4. Upon success, user is redirected to the intended protected page
5. Upon failure, user sees detailed error message with confidence scores and technical details

**Face Liveness Verification:**
1. User completes a Face Liveness challenge (video selfie with movement/light challenges)
2. System extracts reference image from the liveness session
3. Backend uses `SearchFacesByImage` to compare against the collection
4. Verification succeeds if both liveness confidence >= 60% and face similarity >= 60%
5. Note: Face Liveness confidence can vary based on lighting, camera quality, and user cooperation

## Core Technologies

- **Framework**: Laravel 10.x
- **Authentication**: Standard Laravel session-based authentication
- **Step-Up Authentication**:
    - **Amazon Rekognition**: For Face Recognition using `SearchFacesByImage` API
    - **Amazon Rekognition Face Liveness**: For anti-spoofing video selfie verification
    - **Face Collection**: Uses a Rekognition face collection to store registered user faces
    - **AWS SDK for PHP**: To communicate with AWS services from the Laravel backend
    - **Session Management**: Verification status stored in session with configurable timeout
- **Frontend**: 
    - **React**: For Face Liveness UI components
    - **AWS Amplify UI**: `@aws-amplify/ui-react-liveness` - `FaceLivenessDetectorCore` component
    - **Blade Templates**: For traditional forms and layouts
    - **Vite**: Build system for compiling React assets
- **Database**: Any Laravel-compatible database (e.g., MySQL, PostgreSQL, SQLite)
- **Role-Based Access**: Uses `spatie/laravel-permission` to restrict step-up protected routes

## Setup and Installation

1.  **Clone the Repository**

    ```bash
    git clone https://github.com/gerrcass/laravel-liveness-stepup.git
    cd laravel-liveness-stepup
    ```

2.  **Install Dependencies**
    Install both Composer and NPM dependencies.

    ```bash
    composer install
    npm install
    ```

3.  **Create Environment File**
    Copy the example environment file and generate an application key.

    ```bash
    cp .env.example .env
    php artisan key:generate
    ```

4.  **Configure Database**
    Open the `.env` file and set your database connection details.

    ```bash
    DB_CONNECTION=sqlite
    ```

5.  **Run Migrations and Seeders**
    This will create the necessary tables and seed the database with initial data.

    ```bash
    touch database/database.sqlite
    php artisan migrate:fresh --seed
    ```

## Configuration

### AWS Credentials

To use Amazon Rekognition and Face Liveness, you need to configure your AWS credentials. Add the following to your `.env` file:

```dotenv
AWS_ACCESS_KEY_ID=your_aws_access_key
AWS_SECRET_ACCESS_KEY=your_aws_secret_key
AWS_DEFAULT_REGION=us-east-1
AWS_S3_BUCKET=your_s3_bucket_name
```

The IAM user associated with these credentials needs permissions for:

- `rekognition:CreateCollection`
- `rekognition:IndexFaces`
- `rekognition:SearchFacesByImage`
- `rekognition:CreateFaceLivenessSession`
- `rekognition:GetFaceLivenessSessionResults`
- `rekognition:StartFaceLivenessSession`
- `sts:GetSessionToken`

### Face Liveness Configuration

Face Liveness sessions can optionally store audit images in S3. Configure the S3 bucket:

```dotenv
AWS_S3_BUCKET=your-face-liveness-bucket
```

**Important**: When `AWS_S3_BUCKET` is configured, Face Liveness reference images and audit images are stored in S3 instead of being returned as binary data in the API response. The system automatically downloads the reference image from S3 when needed.

### Rekognition Configuration

You can configure the following Rekognition settings:

```dotenv
# Face collection name (default: 'users')
REKOGNITION_COLLECTION_NAME=your-collection-name

# Confidence threshold for face matching (default: 60.0)
REKOGNITION_CONFIDENCE_THRESHOLD=60.0

# S3 prefix for traditional face registration images (default: 'image-sessions/')
AWS_S3_IMAGE_PREFIX=image-sessions/

# S3 prefix for Face Liveness session files (default: 'face-liveness-sessions/')
AWS_S3_LIVENESS_PREFIX=face-liveness-sessions/
```

**Note**: Face verification requires both liveness confidence >= threshold AND face similarity >= threshold to succeed.

You can also configure the step-up verification timeout:

```dotenv
STEPUP_TIMEOUT=900
```

## Running the Application

1.  **Build Frontend Assets**
    Run Vite to compile React assets.
    ```bash
    npm run build
    ```

2.  **Start the Development Server**

    ```bash
    php artisan serve
    ```

3.  **Watch Frontend Assets (Optional)**
    Run the Vite development server in a separate terminal for live reloading.
    ```bash
    npm run dev
    ```

The application will be available at `http://localhost:8000`.

## Key Application Flow

1.  **Registration**: 
    - Choose between "Imagen Facial" (traditional) or "Face Liveness" methods
    - For traditional: Upload a reference face image
    - For Face Liveness: Complete a video selfie challenge with movement/light instructions
    
    **Face Liveness Registration Flow:**
    1. Frontend creates session via `/api/rekognition/create-face-liveness-session-registration`
    2. Backend generates temporary AWS credentials via STS (15-minute expiry)
    3. User completes video selfie challenge in React component
    4. Frontend callback calls `/api/rekognition/complete-liveness-registration-guest`
    5. Backend calls `GetFaceLivenessSessionResults` FIRST (avoiding race condition)
    6. Backend stores results in Laravel session
    7. User submits registration form
    8. Backend indexes face in Rekognition collection using stored results
    9. UserFace record created with face_data and liveness_data
    
2.  **Login**: Standard username/password authentication

3.  **Accessing Protected Routes**: Try to access a sensitive area (requires 'privileged' role)

4.  **Step-Up Verification**: 
    - **Traditional users**: Upload a live image for comparison
    - **Face Liveness users**: Complete another Face Liveness challenge
    
5.  **Access Granted**: Upon successful verification, access is granted for the configured timeout period

## Database Schema - user_faces Table

The `user_faces` table stores face verification data with the following key fields:

- **face_data** (JSON): Contains Rekognition `IndexFaces` response. The `ExternalImageId` field stores the user's ID, enabling face matching during verification.
- **liveness_data** (JSON): Contains complete Face Liveness session results including `SessionId`, `Confidence`, `ReferenceImage` (S3Object), and `AuditImages` for audit trail.
- **verification_status**: Current verification state - `verified`, `pending`, or `failed`.
- **registration_method**: Either `image` (traditional) or `liveness` (video selfie).
- **last_verified_at**: Timestamp of last successful verification.

## Project Structure

- `app/Http/Controllers/RekognitionController.php`: Handles Face Liveness API requests and completion
- `app/Http/Controllers/StepUpController.php`: Manages step-up verification flow for both methods
- `app/Http/Controllers/Auth/RegisterController.php`: Updated to support both registration methods
- `app/Services/RekognitionService.php`: Enhanced with Face Liveness methods
- `app/Models/UserFace.php`: Model for user face data with `face_data`, `liveness_data`, `verification_status`, `registration_method`, and `last_verified_at` fields
- `resources/js/components/FaceLivenessDetector.jsx`: React component for Face Liveness UI with session management and error handling
- `resources/js/app.js`: Main JavaScript entry point with global Face Liveness initialization function
- `resources/views/auth/register.blade.php`: Registration form with method selection and Face Liveness integration
- `resources/views/auth/stepup.blade.php`: Step-up verification with method-specific UI (image upload or Face Liveness)
- `resources/views/stepup_post_redirect.blade.php`: Intermediate page for POST redirects in step-up flow
- `resources/views/special_operation_result.blade.php`: Success page showing verification details
- `resources/views/layouts/app.blade.php`: Main layout with CSRF token and Vite asset loading
- `routes/web.php`: All application routes including Face Liveness endpoints
- `database/migrations/*_add_face_liveness_support_to_user_faces_table.php`: Database schema updates
- `lessons.md`: Documented lessons learned during implementation
