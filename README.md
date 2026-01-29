# Amazon Rekognition Step-Up Authentication with Face Recognition - Laravel Demo

This project is a proof-of-concept demonstrating how to implement **step-up authentication** in a Laravel application using **Amazon Rekognition's Face Recognition** feature. This allows you to require an additional, high-assurance verification step for users attempting to access sensitive parts of your application. Users register with a reference face image, and subsequent verifications compare uploaded images against this reference.

## Table of Contents

- [Amazon Rekognition Step-Up Authentication with Face Recognition - Laravel Demo](#amazon-rekognition-step-up-authentication-with-face-recognition---laravel-demo)
  - [Table of Contents](#table-of-contents)
  - [Project Overview](#project-overview)
  - [How it Works](#how-it-works)
  - [Core Technologies](#core-technologies)
  - [Setup and Installation](#setup-and-installation)
  - [Configuration](#configuration)
    - [AWS Credentials](#aws-credentials)
  - [Running the Application](#running-the-application)
  - [Key Application Flow](#key-application-flow)
  - [Project Structure](#project-structure)

## Project Overview

In many applications, certain actions (e.g., changing account details, transferring funds, accessing admin panels) are more sensitive than others. Standard password-based authentication might not be sufficient to protect these actions. Step-up authentication provides an additional layer of security by requiring the user to re-verify their identity in real-time.

This project uses Amazon Rekognition's Face Recognition (SearchFacesByImage) to verify that the user's uploaded face matches their registered reference image.

## How it Works

1.  A user registers with their standard username, password, and a reference face image. The face image is indexed in a Rekognition face collection with the user's ID as the external identifier.
2.  The user logs into the application using their standard username and password.
3.  When they try to access a protected route (a "special operation"), the application checks if they have recently completed a step-up verification (within a configurable timeout, default 900 seconds).
4.  If they haven't or the timeout has expired, they are redirected to a verification page.
5.  The user uploads a face image through a form.
6.  The backend uses Rekognition's `SearchFacesByImage` API to search for matching faces in the collection. The verification succeeds if:
    - A match is found with similarity confidence >= 85%
    - The matched face's `ExternalImageId` matches the current user's ID
7.  Upon successful verification, the backend marks the user's session as "verified" with a timestamp, valid for the configured timeout period (`STEPUP_TIMEOUT` environment variable).
8.  The user is then redirected to the sensitive page they originally requested.
9.  Subsequent access attempts within the timeout period will bypass verification.

## Core Technologies

- **Framework**: Laravel 10.x
- **Authentication**: Standard Laravel session-based authentication.
- **Step-Up Authentication**:
    - **Amazon Rekognition**: For Face Recognition using `SearchFacesByImage` API.
    - **Face Collection**: Uses a Rekognition face collection to store registered user faces.
    - **AWS SDK for PHP**: To communicate with AWS services from the Laravel backend.
    - **Session Management**: Verification status stored in session with configurable timeout.
- **Database**: Any Laravel-compatible database (e.g., MySQL, PostgreSQL, SQLite).
- **Frontend**: Blade templates with HTML forms for uploading face images.
- **Role-Based Access**: Uses `spatie/laravel-permission` to restrict step-up protected routes to users with the 'privileged' role.

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
    Open the `.env` file and set your database connection details (`DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).

    ```bash
    DB_CONNECTION=sqlite
    ```

5.  **Run Migrations and Seeders**
    This will create the necessary tables and seed the database with initial data (e.g., user roles). Make sure to create the empty `database.sqlite` file first.

    ```bash
    touch database/database.sqlite
    php artisan migrate:fresh --seed
    ```

## Configuration

### AWS Credentials

To use Amazon Rekognition, you need to configure your AWS credentials. Add the following to your `.env` file:

```dotenv
AWS_ACCESS_KEY_ID=your_aws_access_key
AWS_SECRET_ACCESS_KEY=your_aws_secret_key
AWS_DEFAULT_REGION=us-east-1
```

The IAM user associated with these credentials needs permissions for Rekognition. A sample policy would include:

- `rekognition:CreateCollection`
- `rekognition:IndexFaces`
- `rekognition:SearchFacesByImage`
- `rekognition:ListFaces` (optional, for debugging)

Additionally, you can configure a timeout for step-up verification sessions:

```dotenv
STEPUP_TIMEOUT=30
```

This sets the number of seconds that a successful verification remains valid (default: 900 seconds).

## Running the Application

1.  **Start the Development Server**

    ```bash
    php artisan serve
    ```

2.  **Build Frontend Assets**
    Run the Vite development server in a separate terminal.
    ```bash
    npm run dev
    ```

The application will be available at `http://localhost:8000`.

## Key Application Flow

1.  **Registration/Login**: Register a new user with a reference face image (the image is indexed in Rekognition) or log in with an existing one.
2.  **Accessing a Protected Route**: After logging in, try to access a sensitive area of the application. The project includes a "Special Operation" button on the dashboard for this purpose (only visible to users with the 'privileged' role).
3.  **Step-Up Verification**: If verification hasn't been completed recently, you will be redirected to the step-up verification page. Upload a face image that matches your registered face.
4.  **Access Granted**: Upon successful verification (confidence >= 85% and matching user ID), you will be redirected to the special operation result page, confirming that you have passed the step-up check. The verification status is stored in your session and remains valid for the configured timeout period.
5.  **Subsequent Access**: If you attempt to access the protected route again within the timeout period, verification will be bypassed automatically.

## Project Structure

- `app/Http/Controllers/RekognitionController.php`: Handles Rekognition API requests. The main step-up flow uses SearchFacesByImage via StepUpController; this controller also exposes optional AWS Face Liveness session endpoints.
- `app/Http/Controllers/StepUpController.php`: Manages the user-facing part of the step-up flow. Handles image uploads and verifies faces using `RekognitionService::searchFace()`.
- `app/Services/RekognitionService.php`: A service class that encapsulates all interactions with the Amazon Rekognition API. Key methods: `indexFace()` (for registration), `searchFace()` (for verification).
- `app/Services/StsService.php`: A service class for generating temporary AWS credentials using the Security Token Service (STS).
- `app/Http/Middleware/RequireStepUp.php`: The middleware responsible for protecting sensitive routes and triggering the step-up flow. Checks session for recent verification and validates against `STEPUP_TIMEOUT`.
- `routes/web.php`: Defines all application routes, including the protected routes and the API endpoints for Rekognition.
- `resources/views/auth/stepup.blade.php`: The view file containing a form for uploading face images for verification.
- `AGENTS.md`: A file with instructions for AI agents working on this project.
