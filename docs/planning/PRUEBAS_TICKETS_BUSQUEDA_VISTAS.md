# Pruebas: búsqueda por cédula y nueva forma de cambiar de vista en la bandeja

Pedido de Ramiro (19-nov-2026):
1. Buscar solicitudes por la cédula del estudiante, por ejemplo 8-1061-1927.
2. Que pasar entre «Sin asignar», «Míos», «Mi área», etc. sea más fácil.

- Plugin: rama `feat/tickets-busqueda-pestanas`, sale de `origin/master` (`3d5f375b`), versión `20261001128`.
- No cambia la base de datos: solo código y pantallas. El upgrade solo sube la versión y limpia cachés, así los navegadores toman el JS nuevo.
- Portal: no cambia.

Staging se trata como producción: **los pasos ✋ necesitan el OK explícito de Ramiro**.

## Qué cambia

### Búsqueda (bandeja del personal)

| Se puede buscar por | Ejemplos que encuentran 8-1061-1927 / TKT-2026-0042 |
|---|---|
| Cédula (campo de perfil `documentnumber`; si no hay, `idnumber`) | `8-1061-1927`, `810611927`, `8 1061 1927`, `1061`, `8-1061` |
| Nombre y apellido, en cualquier orden y sin importar tildes ni mayúsculas | `juan perez`, `PÉREZ` |
| Número de solicitud, con o sin guiones o solo una parte | `TKT-2026-0042`, `0042`, `20260042` |
| Asunto | `impresora` |

- Con 2 caracteres o más busca en **todas** las solicitudes que esa persona puede ver, abiertas y cerradas, sin importar la vista. Arriba aparece «N resultados en todas tus solicitudes (abiertas y cerradas)» con el botón **Limpiar**. Con 1 carácter muestra «Escribe al menos 2 caracteres».
- En cada resultado aparece la cédula, para confirmar que es la persona correcta.
- **Permisos:** la búsqueda se suma al mismo filtro de visibilidad de la bandeja (`desk_where`). Lo confidencial de Psicología solo lo encuentra quien puede verlo, ni por número ni por cédula.
- **Rendimiento:** se consulta 400 ms después de dejar de escribir y solo con 2 caracteres o más. Cada palabra se busca dentro de lo que el usuario ya puede ver. La cédula se busca con un `EXISTS` sobre `user_info_data`, que tiene índice único usuario+campo, y la lista trae como máximo 100 filas.

### Vistas (antes «pestañas»)

- Las 6 pestañas pasan a ser **tarjetas con contador**, 3 por fila (2 filas), igual en el celular y en el escritorio. El número se ve grande y la tarjeta activa queda en azul.
- Orden: **Míos · Sin asignar · Vencidos · Mi área · Todos · Cerrados**. «Todos» solo aparece a quien puede reasignar.
- «Sin asignar» y «Vencidos» se ponen **en rojo** cuando tienen solicitudes.
- **Recuerda la última vista** de cada persona en ese navegador. La primera vez abre en «Míos». El enlace del indicador flotante (`?tab=`) manda sobre lo recordado.
- **Filtros rápidos** debajo de las tarjetas, en una fila que se desliza con el dedo. Al tocar uno otra vez se quita.
  - **Área**, con su contador. Solo aparece si la vista tiene solicitudes de más de un área.
  - **Semáforo:** Rojo, Naranja, Amarillo y Verde, con contador. Solo en las vistas de abiertas. En «Vencidos» todo es rojo y en «Cerrados» no aplica.
- Si un filtro deja la lista vacía, aparece el botón **Quitar filtros**.

### Ayuda TI (docentes)

Las solicitudes son solo las propias, así que no hace falta buscar por cédula. Con más de 5 solicitudes aparece un buscador por número o asunto que filtra en el mismo navegador, sin consultar al servidor.

## 1. Despliegue en staging ✋

1. Aplicar la rama y correr el upgrade a `20261001128`. No crea tablas.
2. Abrir la bandeja: debe verse con tarjetas. Si se ve con pestañas, el navegador tiene el JS viejo: recargar o purgar cachés.

## 2. Autotest con rollback ✋

`sudo -u www-data php local/grupomakro_core/cli/support_selftest.php --student=<id de prueba>`

La sección **Busqueda y filtros de la bandeja** comprueba:
- la búsqueda por cédula exacta, sin guiones y por una parte (se omite si el estudiante de prueba no tiene cédula);
- la búsqueda por nombre y apellido, por número con y sin guiones, y por asunto;
- que 1 carácter no busca y que una búsqueda sin coincidencias devuelve 0;
- que el filtro de área acota la búsqueda;
- que en Psicología quien no tiene permiso no encuentra la solicitud y su agente sí;
- que los contadores por área suman el total de la vista;
- que el filtro por área y el de semáforo solo traen lo suyo y cuadran con su contador;
- que en Cerrados el semáforo no filtra.

## 3. Pantallas ✋ (teléfono y escritorio)

| # | Paso | Esperado |
|---|---|---|
| 1 | Abrir Mesa de ayuda por primera vez. | Tarjetas 3×2 con contadores; «Míos» activa en azul; «Sin asignar» y «Vencidos» en rojo si tienen. |
| 2 | Tocar «Sin asignar», cerrar la página y volver a abrir. | Abre directo en «Sin asignar». |
| 3 | Entrar desde el indicador flotante (`?tab=overdue`). | Abre en «Vencidos», aunque la última vista haya sido otra. |
| 4 | Buscar la cédula de un estudiante real con guiones, luego sin guiones y luego solo el número del medio. | En los tres casos aparecen sus solicitudes, abiertas y cerradas, con la cédula visible en cada una. |
| 5 | Buscar «nombre apellido» sin tildes. | Aparece. |
| 6 | Buscar un TKT por la parte final (`0042`). | Aparece. |
| 7 | Tocar «Limpiar» o tocar una tarjeta mientras se busca. | Vuelve a la vista con sus filtros. |
| 8 | En «Mi área», tocar un área y luego «Rojo». | La lista se acota. Al tocarlos otra vez se quitan. Los números de los chips coinciden con la lista. |
| 9 | Un agente académico busca un TKT de Psicología. | No lo encuentra. Su agente sí. |
| 10 | Celular: la fila de filtros. | Se desliza con el dedo y la página no se mueve hacia los lados. |
| 11 | Docente → Ayuda TI con más de 5 solicitudes. | Aparece el buscador por número o asunto. |
