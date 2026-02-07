# Lecciones Aprendidas: Integración de Face Liveness con AWS Amplify y Laravel

## Visión General

Este documento captura las lecciones clave aprendidas durante la implementación de Amazon Rekognition Face Liveness en una aplicación Laravel con frontend React.

## Gestión de Sesiones de AWS Face Liveness

### Problema: Restricciones de Formato en ClientRequestToken

**Problema**: AWS `CreateFaceLivenessSession` tiene restricciones de formato en `ClientRequestToken`. Pasar IDs de usuario (ej. "18") causaba `InvalidParameterException`.

**Solución**: No pasar ID de usuario como `ClientRequestToken`. Dejar que AWS genere los IDs de sesión automáticamente:
```php
// INCORRECTO:
$session = $rekognition->createFaceLivenessSession((string) $user->id);

// CORRECTO:
$session = $rekognition->createFaceLivenessSession(null, [], false);
```

### Problema: S3 OutputConfig Causa Errores en el Componente

**Problema**: Al usar `OutputConfig` con bucket S3, el componente `FaceLivenessDetectorCore` de AWS Amplify reportaba errores de sesión.

**Solución**: Crear sesiones SIN S3 para compatibilidad con el componente del frontend:
```php
// Usar S3 solo para procesamiento del backend, no para sesiones del frontend
$session = $rekognition->createFaceLivenessSession(null, [], false); // false = sin S3
```

## Componente AWS Amplify FaceLivenessDetectorCore

### Problema: Errores del Componente con Credenciales Vacías

**Problema**: `FaceLivenessDetectorCore` llamaba a `credentialProvider` múltiples veces. Si las credenciales no estaban en caché correctamente, fallaba.

**Solución**: Asegurarse de que las credenciales se devuelvan correctamente desde el callback:
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

### Problema: Sesión No Encontrada Después de Creación

**Problema**: `FaceLivenessDetectorCore` reportaba "Session not found" inmediatamente después de crear la sesión.

**Solución**:
1. Asegurarse de que la sesión esté completamente creada antes de renderizar el componente
2. No reutilizar IDs de sesión - cada verificación Face Liveness necesita una sesión nueva
3. Las sesiones expiran después de 3 minutos

## Integración S3 con Face Liveness

### Manejo de Datos Binarios

Los resultados de Face Liveness pueden contener datos binarios grandes que no pueden ser codificados en JSON. Implementar limpieza:

```php
private function cleanLivenessResultForStorage(array $livenessResult): array
{
    $cleaned = $livenessResult;
    
    // Eliminar Bytes de ReferenceImage
    if (isset($cleaned['ReferenceImage']['Bytes'])) {
        $bytesLength = strlen($cleaned['ReferenceImage']['Bytes']);
        unset($cleaned['ReferenceImage']['Bytes']);
        $cleaned['ReferenceImage']['HasBytes'] = true;
    }
    
    return $cleaned;
}
```

### S3Object vs Bytes

Cuando `AWS_S3_BUCKET` está configurado:
- Los resultados contienen `S3Object` en lugar de `Bytes`
- Cuando NO está configurado el bucket S3:
- Los resultados contienen `Bytes` directamente

Manejar ambos casos:
```php
private function getReferenceImageBytes(array $sessionResults): string
{
    // Verificar si los bytes están disponibles directamente
    if (isset($sessionResults['ReferenceImage']['Bytes'])) {
        return $sessionResults['ReferenceImage']['Bytes'];
    }
    
    // Verificar si la imagen está almacenada en S3
    if (isset($sessionResults['ReferenceImage']['S3Object'])) {
        $s3Object = $sessionResults['ReferenceImage']['S3Object'];
        $bucket = $s3Object['Bucket'] ?? env('AWS_S3_BUCKET');
        $key = $s3Object['Name'] ?? null;
        
        // Descargar desde S3...
    }
    
    throw new \Exception('No reference image found');
}
```

## Requisitos de CSRF Token

El componente React de Face Liveness requiere token CSRF para las llamadas API. Asegurarse de que la meta etiqueta esté presente:

```html
<meta name="csrf-token" content="{{ csrf_token() }}">
```

El componente lee este token:
```javascript
'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
```

## Configuración de Vite y React

### Problema: React No Carga Después del Build

**Problema**: El bundle de JavaScript no cargaba React después de `npm run build`.

**Solución**: Asegurarse de que los assets de Vite se carguen correctamente en el layout:
```php
// En resources/views/layouts/app.blade.php
@vite(['resources/css/app.css', 'resources/js/app.js'])
```

### Tamaño del Bundle

AWS Amplify UI React Liveness añade un tamaño significativo al bundle (~1.8MB). Esto es esperado debido al complejo componente de Face Liveness.

## Rutas de Laravel

### Problema: Método GET No Soportado para Rutas Protegidas

**Problema**: `/special-operation` solo aceptaba POST, causando errores 405 cuando se redirigía desde el frontend.

**Solución**: Agregar ruta GET para operaciones protegidas:
```php
Route::get('/special-operation', function () {
    $user = auth()->user();
    return view('special_operation_result', [
        'user' => $user,
        'verification' => session('stepup_verification_result'),
    ]);
})->middleware(['auth', 'require.stepup'])->name('special.operation.get');
```

## Variabilidad de Confianza en Face Liveness

### Comportamiento Normal

Los puntajes de confianza de Face Liveness pueden variar significativamente entre intentos:
- Buena iluminación + posicionamiento adecuado: 90-99%
- Condiciones moderadas: 70-90%
- Condiciones pobres: menos de 60%

### Configuración de Umbral

Bajar el umbral para pruebas para evitar falsos negativos:
```php
// En RekognitionController y StepUpController
if ($externalId == (string) $user->id && $faceConfidence >= 60.0 && $livenessConfidence >= 60.0) {
    $success = true;
}
```

## Mejores Prácticas de Pruebas

1. **Usar sesiones frescas**: Cada intento de Face Liveness necesita una sesión nueva
2. **Forzar recarga del navegador**: Después de cambios de código, usar `Ctrl+Shift+R`
3. **Revisar logs de consola**: El componente muestra información de depuración en la consola del navegador
4. **Múltiples intentos**: Face Liveness está diseñado para fallar a veces por seguridad

## Resumen de Estructura de Archivos

Archivos clave para Face Liveness:
- `app/Services/RekognitionService.php` - Wrapper de AWS Rekognition
- `app/Services/StsService.php` - STS para credenciales temporales
- `app/Http/Controllers/RekognitionController.php` - Endpoints API
- `resources/js/components/FaceLivenessDetector.jsx` - Componente React
- `resources/js/app.js` - Inicialización de React
- `routes/web.php` - Todas las rutas incluyendo endpoints de Face Liveness

## Variables de Entorno Requeridas

```dotenv
AWS_ACCESS_KEY_ID=tu_access_key
AWS_SECRET_ACCESS_KEY=tu_secret_key
AWS_DEFAULT_REGION=us-east-1
AWS_S3_BUCKET=nombre_de_tu_bucket  # Opcional para Face Liveness
STEPUP_TIMEOUT=900  # segundos
```

## Permisos IAM Requeridos

```
rekognition:CreateCollection
rekognition:IndexFaces
rekognition:SearchFacesByImage
rekognition:CreateFaceLivenessSession
rekognition:GetFaceLivenessSessionResults
rekognition:StartFaceLivenessSession
sts:GetSessionToken
s3:GetObject  # Si usas bucket S3
```

## Mejoras de UI/UX (Febrero 2026)

### Manejo Mejorado de Errores

El componente FaceLivenessDetector fue mejorado con:

1. **Mensajes de Error Detallados**: En lugar de alertas genéricas, los usuarios ahora ven:
   - Razones específicas de fallo (baja confianza de liveness, cara no coincidió, etc.)
   - Visualización de puntaje de confianza (%, de coincidencia de cara)
   - Comparación con requisitos de umbral

2. **Sugerencias Accionables**: Consejos específicos basados en el tipo de error:
   - Baja confianza de liveness: iluminación, posicionamiento, sugerencias de movimiento
   - Cara no coincidió: consistencia con consejos de foto de registro
   - Cara no encontrada: guía de encuadre y visibilidad

3. **Indicador de Progreso**: Retroalimentación visual durante el análisis de verificación
   - Animación de spinner durante procesamiento
   - Mensaje de estado "Analizando..."

4. **Flujo de Reintento Más Suave**: Sin más recargas de página en fallo
   - Botón "Try Again" reinicia el estado del componente
   - Mantiene el contexto de la página y posición de desplazamiento
   - Los usuarios pueden reintentar inmediatamente sin perder su lugar

### Tipos de Errores Manejados

```javascript
const errorTypes = {
    low_liveness_confidence: 'La verificación de liveness no cumplió con requisitos de seguridad',
    face_not_matched: 'La cara no coincidió con la imagen registrada',
    face_not_found: 'Cara no detectada en el marco',
    component: 'Error del componente Face Liveness',
    session_creation: 'Error al crear sesión de verificación',
    network: 'Error de red durante la verificación'
};
```

### Mejoras en la Página de Step-Up

La página de verificación step-up ahora muestra:
- Puntajes de confianza formateados con indicadores de pasa/falla
- Respuesta cruda de Rekognition expandable (oculta por defecto)
- Mejor jerarquía visual y codificación por colores
- Mejoras de diseño responsivo

## Solución de Problemas Comunes

### Error "Cannot read 'image.png'"

**Síntoma**: La consola muestra error: `Cannot read "image.png" (this model does not support image input)`

**Causa**: Este es un problema conocido con el componente FaceLivenessDetectorCore de AWS Amplify. Ocurre cuando:

1. La carga de assets internos del componente falla
2. Incompatibilidad de versión entre `@aws-amplify/ui-react-liveness` y `aws-amplify`
3. El componente intenta cargar assets de respaldo cuando la comunicación de sesión falla

**Soluciones**:

1. **Asegurarse de que CSS esté importado**:
   ```javascript
   // En app.js
   import '@aws-amplify/ui-react-liveness/styles.css';
   ```

2. **Agregar manejo específico de errores para este error**:
   ```javascript
   const handleError = (err) => {
       if (err.message?.includes('image.png') || err.message?.includes('Cannot read')) {
           // Manejar específicamente - sugerir recarga
           setError('Error de sesión. Por favor recarga e intenta de nuevo.');
       }
   };
   ```

3. **Usar modo de reintento manual**:
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

4. **Verificar versiones de paquetes**: Asegurar versiones compatibles:
   ```json
   {
       "@aws-amplify/ui-react-liveness": "^3.0.0",
       "aws-amplify": "^6.0.0"
   }
   ```

### Entendiendo "success: false"

**Pregunta**: Cuando veo `success: false` en DevTools, ¿debo asumir que la verificación de liveness falló?

**Respuesta**: **Sí**, `success: false` significa que la verificación de Face Liveness falló. Esto puede pasar debido a:

1. **Baja confianza de liveness** (< 60%): El sistema no pudo verificar que la cara es real (no es foto/video)
2. **Cara no coincidió**: La cara no coincide con la imagen registrada
3. **Cara no encontrada**: No se detectó cara en el marco
4. **Error de sesión**: Error del componente o de red

El componente muestra el resultado en la consola, pero también debes revisar la UI para:
- Mensajes de error detallados
- Puntajes de confianza (% de liveness y % de coincidencia de cara)
- Sugerencias accionables para mejora

### Problema de Renderización de Figura Negra

**Pregunta**: ¿Por qué hay una figura negra mostrada debajo de "Check complete"? ¿No debería el área ovalada estar sobre esa figura negra?

**Causa**: Este es un problema de renderización con el componente FaceLivenessDetectorCore donde la superposición de detección de cara no se renderiza correctamente en ciertas condiciones.

**Soluciones**:

1. **Asegurarse de que el componente esté montado correctamente**:
   ```javascript
   const [componentReady, setComponentReady] = useState(false);

   // Solo renderizar componente después de que la sesión esté lista
   {componentReady && (
       <FaceLivenessDetectorCore ... />
   )}
   ```

2. **Agregar fix de CSS z-index** (si es necesario):
   ```css
   .amplify-face-liveness-detector {
       z-index: 1000 !important;
       position: relative;
   }
   ```

3. **Intentar un navegador diferente**: Algunos navegadores pueden tener problemas de renderización con el componente.

4. **Recargar la página**: El componente puede no haberse inicializado correctamente.

**Nota**: Esto parece ser un problema conocido de renderización en el componente de AWS Amplify. La verificación subyacente aún funciona correctamente - es solo un problema de visualización.

## Puntuaciones Comunes de Confianza de Face Liveness

| Rango de Puntuación | Interpretación | Recomendación |
|---------------------|----------------|---------------|
| 90-99% | Excelente | La verificación debería pasar |
| 70-89% | Bueno | Probablemente pase, pero asegurar buenas condiciones |
| 60-69% | En el límite | Puede fallar - mejorar iluminación y posicionamiento |
| < 60% | Pobre | Fallará - ver consejos abajo |

### Consejos para Obtener Puntuaciones de Confianza Más Altas

1. **Iluminación**: Usar iluminación uniforme y difusa. Evitar contraluz (luz detrás de ti).
2. **Posicionamiento**: Mantener la cara centrada y a una distancia consistente.
3. **Movimiento**: Seguir las instrucciones en pantalla para movimiento de cabeza.
4. **Consistencia**: Parecerse a tu foto de registro (gafas, expresión, etc.).
5. **Fondo**: Usar un fondo liso y neutro.

## Validación de Formulario Laravel con Campos Condicionales

### Problema: Middleware ConvertEmptyStringsToNull Rompe Validación Condicional

**Problema**: Al usar la regla de validación `exclude_if` de Laravel, los campos ocultos vacíos estaban siendo convertidos a `null` por el middleware `ConvertEmptyStringsToNull`, causando que la validación fallara con error "The field must be a string".

**Síntoma**: Mensaje de error: "The liveness session id field must be a string."

**Solución**: Usar `exclude_if:registration_method,image` para saltarse completamente la validación cuando se cumple la condición:
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

**Perspectiva Clave**: `exclude_if` y `exclude_unless` saltan completamente las reglas de validación cuando se cumple la condición, evitando que el middleware convierta strings vacíos a null para esos campos.

## Colocación de Código en Lógica Condicional

### Problema: Bloque de Código Dentro del Condicional Incorrecto

**Problema**: Un bloque de código para manejar "no se encontró coincidencia de cara" estaba colocado **dentro** de un bloque `if (count($matches) > 0)`, causando que nunca se ejecutara cuando no se encontraron coincidencias de cara.

**Síntoma**: Usuarios con caras inválidas veían una página en blanco en lugar de un mensaje de error.

**Solución**: Mover el código de manejo de errores **fuera** del bloque condicional:
```php
// INCORRECTO - código dentro del if, nunca se ejecuta cuando count es 0
if (count($matches) > 0) {
    // ... manejo de coincidencias ...
    
    // No se encontró coincidencia de cara - ¡ESTE CÓDIGO NUNCA SE EJECUTA!
    return redirect()->route('stepup.show')->withErrors([...]);
}

// CORRECTO - código fuera del if
if (count($matches) > 0) {
    // ... manejo de coincidencias ...
}

// No se encontró coincidencia de cara - ESTE CÓDIGO SE EJECUTA cuando count es 0
return redirect()->route('stepup.show')->withErrors([...]);
```

**Perspectiva Clave**: Siempre verificar que el código de manejo de errores esté placed en el nivel de scope correcto. El código dentro de un condicional solo se ejecuta cuando esa condición es verdadera.

## Diferencias de Flujo de Redirección: Caras Válidas vs Inválidas

### Diferencia de Comportamiento

**Observación**: La verificación con caras válidas parece más lenta que con caras inválidas.

**Explicación**: Los flujos son fundamentalmente diferentes:

- **Caras inválidas**: Redirección directa a `/step-up` con datos de sesión de error (1 solicitud)
- **Caras válidas**: Redirección a través de `stepup_post_redirect` con formulario oculto + JavaScript (2+ solicitudes)

**Flujo de Cara Válida**:
```
1. POST /step-up/verify → Verificación exitosa
2. Retornar view('stepup_post_redirect') con formulario oculto
3. Navegador carga página, JavaScript hace auto-submit del formulario
4. POST /special-operation → Página de éxito
```

**Flujo de Cara Inválida**:
```
1. POST /step-up/verify → Verificación falló
2. Redirección a /step-up (GET) con datos de error
3. Página carga con mensaje de error
```

**Perspectiva Clave**: El paso extra para caras válidas es necesario para mantener datos POST para operaciones protegidas. La diferencia de rendimiento es comportamiento esperado, no un error.

## Paso de Datos de Sesión a Través de Redirecciones

### Problema: Datos de Verificación Perdidos en Flujo POST

**Problema**: Los datos de verificación se generaban después de la redirección a `stepup_post_redirect`, causando que no estuvieran disponibles cuando el formulario se enviaba a `/special-operation`.

**Solución**: Generar y almacenar datos de verificación ANTES de cualquier redirección:
```php
// Generar datos de verificación PRIMERO
$verificationData = [
    'method' => 'image',
    'confidence' => $confidence,
    // ... otros campos
];

// Almacenar en sesión para ambos flujos GET y POST
$request->session()->put('stepup_verification_result', $verificationData);

// Ahora es seguro redirigir
return view('stepup_post_redirect', compact('targetUrl', 'inputs', 'verificationData'));
```

**Perspectiva Clave**: Al usar páginas de redirección intermedias (como `stepup_post_redirect`), los datos de verificación deben almacenarse en sesión ANTES de retornar la vista, no después.

## Métodos de Envío de Formulario: Normal vs AJAX

### Problema: Formulario Enviado vía fetch en Lugar de Envío Normal

**Problema**: El formulario de verificación estaba siendo enviado usando `fetch` (AJAX) en lugar del envío normal del navegador, causando que las redirecciones no funcionaran correctamente.

**Síntoma**: El usuario era redirigido a `/step-up/verify` (el endpoint POST) con una página en blanco en lugar de ser redirigido a `/step-up` con errores.

**Investigación**: Revisar la pestaña Network de DevTools para el método de solicitud real siendo usado.

**Solución**: Usar redirecciones explícitas en lugar de `back()`:
```php
// Usar redirección de ruta explícita en lugar de back()
return redirect()->route('stepup.show')->withErrors(['face' => 'Verificación fallida']);
```

**Perspectiva Clave**: `back()->withErrors()` depende de HTTP_REFERER que puede no ser siempre confiable. Usar `redirect()->route()` explícito es más robusto.

## Técnicas de Depuración para Controladores Laravel

### Agregar Logs Estratégicos

Al depurar lógica compleja del controlador, agregar logs en puntos clave:
```php
public function verify(Request $request, RekognitionService $rekognition)
{
    logger('verify - llamado');
    logger('verify - métodoRegistro: ' . $metodoRegistro);
    logger('verify - entrando en bloque de verificación de imagen');
    logger('verify - validación pasada');
    logger('verify - searchFace completado', ['FaceMatches' => count($result['FaceMatches'] ?? [])]);
    logger('verify - coincidencias count: ' . count($coincidencias));
    logger('verify - no se encontró coincidencia de cara, redirigiendo...');
}
```

**Perspectiva Clave**: El logging progresivo ayuda a identificar exactamente dónde la ejecución del código se detiene o toma un camino diferente al esperado.

## UI de Errores Mejorada para Verificación Step-Up

### Problema: Mensajes de Error Genéricos

**Problema**: Los usuarios veían solo mensajes de error genéricos como "Verificación fallida" sin retroalimentación accionable.

**Solución**: UI de errores mejorada con detalles técnicos e indicadores visuales:
```php
// En plantilla Blade - Área de error con retroalimentación detallada
@if($errors->any() || session('stepup_error_details'))
    <div style="color:#721c24; background-color:#f8d7da; border:1px solid #f5c6cb;">
        <h3 style="margin-top:0;">Verificación de Cara Fallida</h3>
        <p><strong>Razón:</strong> {{ $errorMessage }}</p>

        {{-- Detalles técnicos con puntajes de confianza codificados por color --}}
        @if($livenessConf)
            <p>
                <strong>Confianza de Liveness:</strong>
                <span style="color: {{ $livenessConf >= 60 ? '#28a745' : '#dc3545' }};">
                    {{ number_format($livenessConf, 1) }}%
                    {{ $livenessConf >= 60 ? '(aprobado)' : '(debajo del umbral de 60%)' }}
                </span>
            </p>
        @endif

        {{-- Acordeón con respuesta cruda de API --}}
        <details>
            <summary>Mostrar respuesta cruda de Rekognition API</summary>
            <pre>{{ json_encode($errorDetails, JSON_PRETTY_PRINT) }}</pre>
        </details>
    </div>
@endif
```

**Perspectiva Clave**: Los usuarios necesitan retroalimentación accionable con puntajes de confianza para entender por qué la verificación falló y cómo mejorar.

### Mostrar Imagen de Verificación Fallida

**Problema**: Los usuarios no podían ver qué imagen habían enviado durante un intento de verificación fallido.

**Solución**: Agregar endpoint para servir imágenes de error desde almacenamiento de sesión:
```php
// StepUpController.php
public function errorImage(Request $request): StreamedResponse
{
    $path = $request->session()->get('stepup_error_image_path');
    
    if (!$path || !Storage::disk('local')->exists($path)) {
        abort(404);
    }
    
    return response()->stream(function () use ($path) {
        $stream = Storage::disk('local')->readStream($path);
        fpassthru($stream);
        fclose($stream);
    }, 200, ['Content-Type' => 'image/jpeg']);
}
```

**Perspectiva Clave**: Mostrar a los usuarios su imagen enviada les ayuda a entender por qué la verificación falló (ej. foto borrosa, mala iluminación).

## UI de Éxito Mejorada para Verificación Step-Up

### Problema: Mensajes de Éxito Inconsistentes

**Problema**: La página de éxito mostraba información diferente para usuarios de liveness vs imagen, dificultando entender qué pasó.

**Solución**: UI de éxito unificada con detalles específicos del método:
```php
// En plantilla Blade
<div style="color:#155724; background-color:#d4edda; border:1px solid #c3e6cb;">
    <h3 style="margin-top:0;">✅ Verificación Exitosa</h3>
    <p>
        <strong>Tu identidad ha sido verificada exitosamente</strong>
        @if($method === 'liveness')
            vía Face Liveness
        @else
            vía verificación basada en imagen
        @endif
    </p>

    {{-- Detalles técnicos --}}
    @if($livenessConfidence !== null)
        <p><strong>Confianza de Liveness:</strong> {{ number_format($livenessConfidence, 1) }}%</p>
    @endif
    @if($confidence)
        <p><strong>Confianza de Coincidencia de Cara:</strong> {{ number_format($confidence, 1) }}%</p>
    @endif
</div>
```

**Perspectiva Clave**: UI consistente con indicadores de éxito claros mejora la confianza del usuario en el sistema de verificación.

## Paso de Datos de Sesión en Flujos de Redirección POST

### Problema: Datos de Verificación Perdidos en stepup_post_redirect

**Problema**: Al usar `stepup_post_redirect` para mantener datos POST, los datos de verificación se generaban después de la redirección, causando que no estuvieran disponibles.

**Solución**: Almacenar datos de verificación en sesión ANTES de retornar la vista de redirección:
```php
// INCORRECTO - datos almacenados después de redirección
return view('stepup_post_redirect', ...);
$request->session()->put('stepup_verification_result', $data); // ¡Demasiado tarde!

// CORRECTO - datos almacenados antes de redirección
$request->session()->put('stepup_verification_result', $verificationData);
return view('stepup_post_redirect', ...);
```

También pasar datos de verificación como input oculto:
```php
{{-- En stepup_post_redirect.blade.php --}}
@if(isset($verificationData))
    <input type="hidden" name="verification_data" value='{{ json_encode($verificationData) }}'>
@endif
```

**Perspectiva Clave**: Al usar páginas de redirección intermedias, todos los datos deben estar disponibles ANTES de la redirección ya que la siguiente carga de página es una solicitud nueva.

## Múltiples Claves de Sesión para Datos de Verificación

### Problema: Datos de Verificación No Disponibles en Todos los Flujos

**Problema**: Diferentes flujos (GET vs POST, liveness vs imagen) usaban diferentes claves de sesión, causando que los datos no estuvieran disponibles en algunos casos.

**Solución**: Usar lógica de respaldo y claves de sesión consistentes:
```php
// Verificar datos flash primero, luego datos de sesión
$verification = session('verification') ?? session('stepup_verification_result');

// Log para depurar
logger('special-operation POST', [
    'user_id' => $user->id,
    'verification_data' => $verification,
    'has_flash_verification' => session()->has('verification'),
    'has_session_verification' => session()->has('stepup_verification_result'),
]);
```

**Perspectiva Clave**: Al soportar múltiples flujos (GET/POST, AJAX/normal), usar claves de sesión consistentes y lógica de respaldo.

## Visualización de Etiqueta de Método de Registro en Layout

### Problema: Usuarios No Podían Indicar Qué Método de Registro Usaron

**Problema**: Después de la integración de Face Liveness, los usuarios registrados con diferentes métodos pero la UI no indicaba qué método habían usado.

**Solución**: Agregar etiqueta de método junto a la miniatura de cara:
```php
@if($hasFaceImage)
    @php
        $methodLabel = $userFace && $userFace->registration_method === 'liveness' 
            ? 'liveness' 
            : 'imagen';
    @endphp
    <span> — Cara registrada ({{ $methodLabel }}):</span>
    <img src="{{ $faceImageUrl }}" alt="Cara registrada">
@endif
```

**Perspectiva Clave**: Indicadores de método claros ayudan a los usuarios a entender su estado de autenticación y flujo de verificación esperado.

## Limpieza de Imágenes de Prueba

### Problema: Imágenes de Prueba Acumuladas

**Problema**: Imágenes de prueba (j1.jpg, j2.jpg, j3.jpg) fueron accidentalmente commitidas al repositorio.

**Solución**: Eliminar imágenes de prueba y agregar a .gitignore si es necesario:
```bash
git rm public/caras/j1.jpg public/caras/j2.jpg public/caras/j3.jpg
```

**Perspectiva Clave**: Mantener archivos de prueba fuera del repositorio o usar un directorio dedicado para datos de prueba.

## Código Duplicado Después del Loop

### Problema: Error "Undefined variable $userFace" Después de Verificación Exitosa

**Problema**: La verificación de Face Liveness mostraba "Verification SUCCESS" en logs pero luego fallaba con error "Undefined variable $userFace".

**Causa Raíz**: Código duplicado existía tanto dentro del loop foreach (para caso de éxito) COMO después del loop. El caso de éxito del loop ya almacenaba todos los datos de verificación y retornaba JSON, pero el código después del loop intentaba hacer lo mismo usando una variable `$userFace` no definida.

**Síntoma**:
```
[2026-02-05 15:55:43] local.DEBUG: Verification SUCCESS for current user {...}
[2026-02-05 15:55:43] local.DEBUG: Face Liveness verification ERROR {"error":"Undefined variable $userFace"...}
```

**Solución**: Eliminar el bloque de código duplicado después del loop ya que el manejo de éxito ya está hecho dentro del foreach:
```php
// ELIMINADO (líneas 395-426):
// if ($userFace) {
//     $userFace->verification_status = 'verified';
//     ...
// }
//
// El caso de éxito dentro del foreach ya maneja:
// - Almacenar stepup_verified_at
// - Almacenar stepup_liveness_verification_image
// - Almacenar stepup_verification_result
// - Retornar JSON con 'verified' => true
```

**Perspectiva Clave**: La duplicación de código puede causar errores sutiles. Al agregar manejo de éxito dentro de un loop, verificar que no haya código duplicado después del loop que podría ejecutarse incorrectamente.

## Prioridad de Imagen de Sesión en Flujos de Múltiples Intentos

### Problema: Imagen de Referencia Incorrecta Mostrada en Verificación Fallida

**Problema**: Al hacer múltiples intentos fallidos de verificación de Face Liveness, la "Imagen de referencia de verificación de Face Liveness" mostrada en la UI de error era de un intento anterior, no del actual.

**Causa Raíz**: El endpoint `livenessVerificationImage` usaba fusión de null (`??`) que podía retornar una imagen de sesión antigua:
```php
// INCORRECTO - '??' retorna el primer valor no null, incluso si es de sesión antigua
$s3Object = $request->session()->get('stepup_liveness_verification_image')
    ?? $request->session()->get('stepup_error_reference_image');
```

**Solución**: Priorizar imagen de referencia de error y solo usar imagen de éxito como respaldo si no existe imagen de error:
```php
// CORRECTO - siempre usar la imagen de error más reciente
$s3Object = $request->session()->get('stepup_error_reference_image');

if (!$s3Object) {
    $s3Object = $request->session()->get('stepup_liveness_verification_image');
}
```

**Perspectiva Clave**: En flujos de múltiples intentos, siempre priorizar los datos del intento más reciente. El operador de fusión de null puede retornar silenciosamente datos obsoletos de intentos anteriores.

## Referencias

- [Documentación de AWS Rekognition Face Liveness](https://docs.aws.amazon.com/rekognition/latest/dg/face-liveness.html)
- [Componente Face Liveness de AWS Amplify UI](https://ui.docs.amplify.aws/react/connected-components/liveness)

## Bug: Valores Hardcodeados en Lugar de Configurables

### Problema: Valores de Colección y Umbral Ignorados

**Síntoma**: La aplicación ignoraba la configuración de `REKOGNITION_COLLECTION_NAME` y `REKOGNITION_CONFIDENCE_THRESHOLD` del archivo `.env`, usando en su lugar valores hardcodeados.

**Causa**: El código en `RekognitionController.php` tenía valores hardcodeados:
```php
// INCORRECTO - valores hardcodeados
$searchResult = $rekognition->searchFaceFromBytes($imageBytes, 'users', 60.0);
$success = ($faceConfidence >= 60.0 && $livenessConfidence >= 60.0);
```

**Investigación**:
1. Verificar configuración: `config('rekognition.collection_name')` retornaba el valor correcto
2. Verificar RekognitionService: `$service->getCollectionId()` retornaba el valor correcto
3. Buscar hardcodeos: `search_and_replace` reveló `'users'` hardcodeado en RekognitionController.php:295

**Solución**: Usar los métodos getter del RekognitionService:
```php
// CORRECTO - usar valores configurables
$searchResult = $rekognition->searchFaceFromBytes(
    $imageBytes,
    $rekognition->getCollectionId(),
    $rekognition->getConfidenceThreshold()
);

// Obtener umbral al inicio del método
$threshold = $rekognition->getConfidenceThreshold();

// Usar threshold en comparaciones
$success = ($faceConfidence >= $threshold && $livenessConfidence >= $threshold);
```

**Perspectiva Clave**: Incluso cuando los valores de configuración se leen correctamente del `.env`, el código puede ignorarlos si hay valores hardcodeados en los controladores. Sempre usar los métodos getter del servicio en lugar de valores literales.

### Verificación de Configuración en Tiempo de Ejecución

Para depurar problemas de configuración, usar `php artisan tinker`:
```php
$service = new \App\Services\RekognitionService();
echo "Collection ID: " . $service->getCollectionId() . "\n";
echo "Confidence Threshold: " . $service->getConfidenceThreshold() . "\n";
```

**Comandos AWS CLI útiles**:
```bash
# Listar todas las colecciones
aws rekognition list-collections

# Verificar qué colección se está usando
aws rekognition list-faces --collection-id "mi-coleccion"
```

## Conflicto de Callbacks JavaScript entre Layout y Páginas

### Problema: Callbacks de Face Liveness Interferidos

**Síntoma**: La verificación de Face Liveness en `/step-up` fallaba con errores "Server issue" y la página mostraba el modal de registro facial en lugar del componente de verificación.

**Causa Raíz**: El layout `app.blade.php` definía globalmente `window.onLivenessComplete` y `window.onLivenessError` que sobrescribían los callbacks definidos específicamente en la página `/step-up`. Esto causaba:
- El callback del step-up nunca se ejecutaba
- Errores de referencia nula al intentar acceder a elementos del modal
- Pérdida del flujo de verificación

**Código problemático**:
```javascript
// app.blade.php - siempre definía estos callbacks
export default function initializeFaceLiveness(onComplete, onError) {
    window.onLivenessComplete = function(result) {
        livenessCompleted = true;
        const sessionInput = document.getElementById('modal_liveness_session_id'); // null en /step-up
        // Error: Cannot set property 'value' of null
    };
}
```

**Solución**: Verificar si los callbacks ya están definidos antes de sobrescribirlos:
```javascript
// Solo definir si no están ya definidos por scripts de página específica
if (typeof window.onLivenessComplete === 'undefined') {
    window.onLivenessComplete = function(result) {
        // ... código del callback global
    };
}

if (typeof window.onLivenessError === 'undefined') {
    window.onLivenessError = function(error) {
        console.error('Face Liveness error:', error);
    };
}
```

**Perspectiva Clave**: Cuando múltiples scripts definen callbacks globales, usar verificación de tipo `typeof === 'undefined'` para evitar sobrescribir callbacks específicos de página que necesitan ejecutarse.

## Registro Facial en Dashboard con Modal

### Implementación de Flujo de Registro Modal

**Contexto**: Se implementó un popup modal en el dashboard para que usuarios sin cara registrada puedan completar su registro facial sin ser redirigidos a una página separada.

**Componentes del Modal**:
1. **Detección de Usuario sin Cara**: El layout verifica si el usuario tiene `userFace` registrado
2. **Renderizado Condicional**: El modal solo se muestra si `!$hasFaceImage && auth()->check() && !session('face_registration_completed')`
3. **Métodos de Registro**: Soporta tanto "Imagen Facial" como "Face Liveness"
4. **Envío AJAX**: El formulario usa fetch para enviar sin recarga de página
5. **Manejo de Errores**: Muestra mensajes de error/success dentro del modal
6. **Sesión de Completado**: Evita mostrar el modal después de registro exitoso

**Estructura del Modal**:
```blade
@if(!$hasFaceImage && auth()->check() && !session('face_registration_completed'))
<div id="face-registration-modal" class="modal-overlay active">
    <div class="modal-content">
        <!-- Método de selección -->
        <input type="radio" name="registration_method" value="image" checked>
        <input type="radio" name="registration_method" value="liveness">
        
        <!-- Contenido según método -->
        <div id="modal-image-method">...</div>
        <div id="modal-liveness-method">...</div>
        
        <!-- Formulario -->
        <form id="face-registration-form">
            @csrf
            <button type="submit">Registrar</button>
            <button type="button" onclick="closeFaceModal()">Cancelar</button>
        </form>
    </div>
</div>
@endif
```

**Lógica JavaScript**:
```javascript
document.addEventListener('DOMContentLoaded', function() {
    // No mostrar modal en /register/face (ruta legacy)
    if (window.location.pathname === '/register/face') {
        modal.classList.remove('active');
    }
});

async function handleSubmit(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    
    const response = await fetch('{{ route("register.face.store") }}', {
        method: 'POST',
        body: formData,
    });
    
    if (response.ok && data.success) {
        // Cerrar modal y recargar página
        setTimeout(() => {
            closeFaceModal();
            window.location.reload();
        }, 1500);
    }
}
```

**Modificación de LoginController**:
El LoginController fue modificado para redirigir al dashboard en lugar de `/register/face`, permitiendo que el modal se muestre allí:
```php
// Redirect al dashboard - el modal se mostrará desde el layout
return redirect()->route('dashboard');
```

**Perspectiva Clave**: Mantener la experiencia del usuario en contexto (dashboard) en lugar de redirigir a páginas separadas mejora la usabilidad y reduce la fricción en el flujo de registro.

---

## Race Condition con AWS Amplify FaceLivenessDetectorCore

### Problema: El Componente Consume los Resultados de la Sesión Antes que el Backend

**Síntoma**: Error `ValidationException: Liveness session has results available. Please get results for the session.`

**Causa Raíz**: El componente `FaceLivenessDetectorCore` de AWS Amplify internamente llama a `GetFaceLivenessSessionResults` después de completar el análisis. Esto causa un race condition cuando:

1. Frontend inicia sesión Face Liveness
2. Usuario completa el challenge
3. Frontend callback llama al backend (`/complete-liveness-registration-guest`)
4. Backend llama a `GetFaceLivenessSessionResults`
5. Component internamente también llama a `GetFaceLivenessSessionResults`
6. Uno de los dos falla porque los resultados ya fueron consumidos

**Solución: Retry con Exponential Backoff en el Backend**

El backend ahora implementa retry automático con exponential backoff:

```php
public function completeLivenessRegistrationGuest(Request $request, RekognitionService $rekognition)
{
    $sessionId = $request->input('sessionId');
    $maxRetries = 5;
    $initialDelayMs = 100;

    for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
        try {
            $livenessResult = $rekognition->getFaceLivenessSessionResults($sessionId);
            // Verificar que tenemos resultados válidos
            if (!isset($livenessResult['SessionId']) || !isset($livenessResult['ReferenceImage'])) {
                // Resultados no listos aún, reintentar
                if ($attempt < $maxRetries - 1) {
                    $delayMs = $initialDelayMs * pow(2, $attempt);
                    usleep($delayMs * 1000);
                    continue;
                }
                throw new \Exception('Invalid liveness session results');
            }
            
            // Procesar resultados...
            return response()->json(['success' => true, 'retries' => $attempt]);
            
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $isResultsNotAvailable = strpos($errorMessage, 'results available') !== false 
                || strpos($errorMessage, 'No such session') !== false;
            
            if ($isResultsNotAvailable && $attempt < $maxRetries - 1) {
                // Race condition: frontend consumió resultados primero, esperar y reintentar
                $delayMs = $initialDelayMs * pow(2, $attempt);
                logger("Race condition detected (attempt $attempt/$maxRetries), waiting {$delayMs}ms");
                usleep($delayMs * 1000);
                continue;
            }
            
            return response()->json(['error' => $errorMessage], 400);
        }
    }
}
```

**Solución: Supresión de Errores en el Frontend**

El componente React ahora detecta y suprime errores relacionados con race condition:

```javascript
const handleError = useCallback((err) => {
    const errorMessage = err.message || err.state || '';
    const isResultsAlreadyConsumed = errorMessage.includes('results available') 
        || errorMessage.includes('No such session');

    // Si el backend ya tuvo éxito, suprimir este error
    if (backendSuccessRef.current && lastResult?.success) {
        console.log('Suppressing component error - backend already processed');
        clearErrorState();
        return;
    }

    // Si estamos esperando respuesta del backend, suprimir temporalmente
    if (isBackendProcessing && !lastResult) {
        suppressErrorsRef.current = true;
        return;
    }

    // Si el componente intenta obtener resultados ya consumidos...
    if (isResultsAlreadyConsumed && lastResult) {
        if (lastResult.success) {
            clearErrorState();
            return;
        }
    }

    // Mostrar otros errores normalmente
    // ...
}, [lastResult, onError, isBackendProcessing]);
```

**Perspectiva Clave**: La combinación de retry en backend + supresión inteligente de errores en frontend proporciona una experiencia robusta contra race conditions. El usuario no debería ver errores técnicos cuando el proceso subyacente tuvo éxito.

### Limpieza de Elementos DOM de Error

El componente AWS Amplify puede crear overlays de error que no se pueden controlar internamente. Limpieza forzada:

```javascript
const clearErrorState = () => {
    setError(null);
    setErrorDetails(null);
    setShowHints(false);
    
    setTimeout(() => {
        const errorSelectors = [
            '.amplify-modal__overlay',
            '.amplify-error',
            '[class*="error"]',
            '[class*="toast"]',
            '[role="alert"]'
        ];
        errorSelectors.forEach(selector => {
            document.querySelectorAll(selector).forEach(el => {
                const text = el.innerText || '';
                if (text.includes('Server issue') || 
                    text.includes('Cannot complete') ||
                    text.includes('results available')) {
                    el.remove();
                }
            });
        });
    }, 100);
};
```

**Perspectiva Clave**: Manipular el DOM directamente es un workaround necesario cuando el componente de terceros tiene comportamiento interno que no podemos controlar. Siempre hacer esto en un `setTimeout` para asegurar que el componente haya terminado su renderizado.
