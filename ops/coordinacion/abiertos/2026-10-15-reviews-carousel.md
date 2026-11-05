# Reseñas del home configurables y explicación de foto/puntos

estado: esperando-respuesta — candidato local; pruebas de código verdes; falta QA independiente UI/E2E y producción
de: reviews_carousel_dev (desarrollo)
para: root (coordinación y QA independiente)
archivos: public_html/wp-content/plugins/akibara-rewards/src/Reviews/Carousel.php; public_html/wp-content/plugins/akibara-rewards/src/Reviews/ReviewForm.php; public_html/wp-content/plugins/akibara-rewards/src/Support/Options.php; public_html/wp-content/plugins/akibara-rewards/src/Support/SettingsPage.php; tests/Reviews/{CarouselTest,FotoObligatoriaTest}.php; tests/Support/SettingsPageTest.php

El dueño pide mostrar reseñas sin foto, configurar modos con/sin/mixto y aclarar que sin foto no hay puntos. Diagnóstico productivo confirmado: dos aprobadas elegibles, mínimo ocho, carrusel encendido. Última reseña 2402 sin foto ni puntos; 2254 con foto y 500 puntos. El bloqueo de visibilidad era el umbral, no un filtro de foto.

Implementado selector en la UI existente, filtrado antes del umbral/máximo con paginación, invalidación de foto, tarjetas con portada pequeña identificada y foto del cliente separada, enlace a opinión completa y textos explícitos en ambos formularios. Montos/política de premios intactos; mínimo existente configurable sin cambio de base.

Paridad de los ocho archivos leídos: producción y local idénticos antes de editar. Candidato cuatro runtime exactos, SHA y parche contra producción en `output/reviews-carousel-20261015/candidate-manifest.json` y `candidate.patch`. Desarrollo no hizo despliegue ni fixtures ni mutaciones de opciones DB.

Validación local de código: host completo 846/7475; Docker final dirigido137/5007 sin omitidas; PHPCS4/4 y diff-check. Root hará fixtures/E2E por separado y dará el visto bueno. Pendiente producción, UI productiva y cierre de QA. Detalle de decisión/límites: `output/reviews-carousel-20261015/decision-y-candidato.md`.
