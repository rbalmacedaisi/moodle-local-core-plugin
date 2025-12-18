require(["jquery", "core/modal_factory", "core/modal_events"], function ($, ModalFactory, ModalEvents) {
    console.log("GMK: AMD Modules Loaded");

    $(document).ready(function () {
        console.log("GMK: Document Ready");

        // Plans Modal
        $(document).on("click", ".view-plans-btn", function (e) {
            e.preventDefault();
            console.log("GMK: Plans Clicked");

            var btn = $(this);
            var courseId = btn.data("courseid");
            var courseName = btn.data("coursename");

            // Fetch data from global object
            var data = (window.gmkCourseData && window.gmkCourseData[courseId]) ? window.gmkCourseData[courseId] : null;
            var plans = data ? data.plans : [];
            console.log("GMK: Plans Data", plans);

            ModalFactory.create({
                type: ModalFactory.types.DEFAULT,
                title: "Planes de Aprendizaje",
                body: "Cargando...",
            }).then(function (modal) {
                var bodyHtml = "<div class='modal-mimic-title'>" + courseName + "</div>";
                bodyHtml += "<div class='modlist'><span class='modlist-header'>Planes Asociados</span><ul class='modules-item-list'>";

                if (plans && plans.length > 0) {
                    $.each(plans, function (i, plan) {
                        bodyHtml += "<li class='item-list'>" +
                            "<div class='custom-avatar'><i class='mdi mdi-notebook-multiple'></i></div>" +
                            "<div class='list-item-info'>" +
                            "<div class='list-item-info-text'><p>" + plan.name + "</p></div>" +
                            "</div>" +
                            "</li>";
                    });
                } else {
                    bodyHtml += "<li class='item-list'><i class='mdi mdi-alert-circle-outline mr-2'></i> Ningún plan asociado</li>";
                }
                bodyHtml += "</ul></div>";

                modal.setBody(bodyHtml);
                modal.show();
            }).fail(function (ex) {
                console.error("GMK: Modal Create Failed", ex);
            });
        });

        // Schedules Modal
        $(document).on("click", ".view-schedules-btn", function (e) {
            e.preventDefault();
            console.log("GMK: Schedules Clicked");

            var btn = $(this);
            var courseId = btn.data("courseid");
            var courseName = btn.data("coursename");

            // Fetch data from global object
            var data = (window.gmkCourseData && window.gmkCourseData[courseId]) ? window.gmkCourseData[courseId] : null;
            var schedules = data ? data.schedules : [];
            console.log("GMK: Schedules Data", schedules);

            ModalFactory.create({
                type: ModalFactory.types.DEFAULT,
                title: "Horarios del Curso",
                body: "Cargando...",
                large: true
            }).then(function (modal) {
                var bodyHtml = "<div class='modal-mimic-title'>" + courseName + "</div>";
                bodyHtml += "<div class='modlist'><span class='modlist-header'>Listado de Horarios</span><ul class='modules-item-list'>";

                if (schedules && schedules.length > 0) {
                    $.each(schedules, function (i, sch) {
                        var isClosed = sch.closed == 1;
                        var statusBadge = isClosed
                            ? "<span class='badge badge-danger ml-2'>CERRADO</span>"
                            : "<span class='badge badge-success ml-2'>ABIERTO</span>";

                        var iconClass = isClosed ? "mdi mdi-lock" : "mdi mdi-calendar-clock";
                        var avatarClass = isClosed ? "custom-avatar" : "custom-avatar blue-avatar";

                        // Date formatting
                        var dateRange = "";
                        if (sch.initdate > 0 && sch.enddate > 0) {
                            var d1 = new Date(sch.initdate * 1000).toLocaleDateString();
                            var d2 = new Date(sch.enddate * 1000).toLocaleDateString();
                            dateRange = " | " + d1 + " - " + d2;
                        }

                        var actionBtn = "";
                        if (isClosed) {
                            actionBtn = "<button class='btn btn-sm btn-outline-primary reopen-schedule-btn' data-id='" + sch.id + "'><i class='mdi mdi-lock-open-variant'></i> Re-abrir</button>";
                        } else {
                            actionBtn = "<button class='btn btn-sm btn-outline-danger close-schedule-btn' data-id='" + sch.id + "'><i class='mdi mdi-lock'></i> Cerrar</button>";
                        }

                        if (sch.approved == 1) {
                            actionBtn += " <button class='btn btn-sm btn-outline-warning revert-schedule-btn' data-id='" + sch.id + "'><i class='mdi mdi-undo'></i> Revertir</button>";
                            // Manual Enrollment Button
                            if (!isClosed) {
                                actionBtn += " <button class='btn btn-sm btn-outline-info enroll-student-btn' data-id='" + sch.id + "' data-name='" + sch.name + "'><i class='mdi mdi-account-plus'></i> Inscribir</button>";
                            }
                        }

                        bodyHtml += "<li class='item-list' style='" + (isClosed ? "opacity:0.8; background:#f9f9f9;" : "") + "'>" +
                            "<div class='" + avatarClass + "'><i class='" + iconClass + "'></i></div>" +
                            "<div class='list-item-info'>" +
                            "<div class='list-item-info-text'>" +
                            "<p>" + sch.name + statusBadge + "</p>" +
                            "<span class='list-item-subtext'>Horario: " + sch.inithourformatted + " - " + sch.endhourformatted + dateRange + "</span>" +
                            "</div>" +
                            "</div>" +
                            "<div class='list-item-actions pl-2'>" + actionBtn + "</div>" +
                            "</li>";
                    });
                } else {
                    bodyHtml += "<li class='item-list'><i class='mdi mdi-alert-circle-outline mr-2'></i> No hay horarios registrados</li>";
                }
                bodyHtml += "</ul></div>";

                modal.setBody(bodyHtml);
                modal.show();
            }).fail(function (ex) {
                console.error("GMK: Modal Create Failed", ex);
            });
        });

        // Re-open / Close Actions
        $(document).on("click", ".reopen-schedule-btn, .close-schedule-btn", function (e) {
            e.preventDefault();
            var btn = $(this);
            var classId = btn.data("id");
            var isOpenAction = btn.hasClass("reopen-schedule-btn");
            var actionText = isOpenAction ? "Re-abrir" : "Cerrar";

            if (!confirm("¿Está seguro que desea " + actionText + " este horario?")) return;

            // Call External Function
            require(['core/ajax'], function (ajax) {
                var promises = ajax.call([{
                    methodname: 'local_grupomakro_toggle_class_status',
                    args: { classId: classId, open: isOpenAction }
                }]);

                promises[0].done(function (response) {
                    location.reload(); // Reload to reflect changes
                }).fail(function (ex) {
                    alert("Error: " + ex.message);
                });
            });
        });

        // Revert Approval Action
        $(document).on("click", ".revert-schedule-btn", function (e) {
            e.preventDefault();
            var btn = $(this);
            var classId = btn.data("id");

            if (!confirm("¿Está seguro que desea REVERTIR la aprobación de este horario?\nEsto eliminará el grupo asociado y devolverá a los estudiantes a pre-inscripción.")) return;

            require(['core/ajax'], function (ajax) {
                var promises = ajax.call([{
                    methodname: 'local_grupomakro_revert_approval',
                    args: { classId: classId }
                }]);

                promises[0].done(function (response) {
                    if (response.status === 'ok') {
                        location.reload();
                    } else {
                        alert(response.message);
                    }
                }).fail(function (ex) {
                    alert("Error: " + ex.message);
                });
            });
        });

        // Manual Enrollment Action
        $(document).on("click", ".enroll-student-btn", function (e) {
            e.preventDefault();
            var btn = $(this);
            var classId = btn.data("id");
            var className = btn.data("name");

            ModalFactory.create({
                type: ModalFactory.types.DEFAULT,
                title: "Inscribir Estudiante - " + className,
                body: "Cargando...",
            }).then(function (modal) {
                var bodyHtml = "<div>" +
                    "<div class='form-group'>" +
                    "<label>Buscar Usuario (Nombre, Email)</label>" +
                    "<div class='input-group'>" +
                    "<input type='text' class='form-control' id='user_search_input' placeholder='Min 3 caracteres...'>" +
                    "<div class='input-group-append'>" +
                    "<button class='btn btn-primary' id='user_search_btn'>Buscar</button>" +
                    "</div>" +
                    "</div>" +
                    "</div>" +
                    "<div id='user_search_results' style='max-height: 200px; overflow-y: auto;' class='mt-2'></div>" +
                    "</div>";

                modal.setBody(bodyHtml);
                modal.show();

                // Bind Search Event inside Modal RE-BINDING needed dynamically usually, but document.on works if selectors are unique enough or scoped
                // Let's store classId in the search button data for easy access
                setTimeout(function () {
                    $('#user_search_btn').data('classid', classId);
                }, 100);

            }).fail(function (ex) {
                console.error("GMK: Modal Create Failed", ex);
            });
        });

        // User Search Logic
        $(document).on("click", "#user_search_btn", function (e) {
            e.preventDefault();
            var btn = $(this);
            var query = $('#user_search_input').val();
            var resultsDiv = $('#user_search_results');
            var classId = btn.data('classid');

            if (query.length < 3) {
                alert("Ingrese al menos 3 caracteres.");
                return;
            }

            resultsDiv.html('<div class="text-center"><i class="fa fa-spinner fa-spin"></i> Buscando...</div>');

            require(['core/ajax'], function (ajax) {
                var promises = ajax.call([{
                    methodname: 'local_grupomakro_search_users',
                    args: { query: query }
                }]);

                promises[0].done(function (users) {
                    var html = "<ul class='list-group'>";
                    if (users.length > 0) {
                        $.each(users, function (i, u) {
                            html += "<li class='list-group-item d-flex justify-content-between align-items-center'>" +
                                "<div><strong>" + u.fullname + "</strong><br><small>" + u.email + "</small></div>" +
                                "<button class='btn btn-sm btn-success perform-enroll-btn' data-userid='" + u.id + "' data-classid='" + classId + "'>Inscribir</button>" +
                                "</li>";
                        });
                    } else {
                        html += "<li class='list-group-item'>No se encontraron usuarios.</li>";
                    }
                    html += "</ul>";
                    resultsDiv.html(html);
                }).fail(function (ex) {
                    resultsDiv.html('<span class="text-danger">Error: ' + ex.message + '</span>');
                });
            });
        });

        // Perform Enrollment
        $(document).on("click", ".perform-enroll-btn", function (e) {
            e.preventDefault();
            var btn = $(this);
            var userId = btn.data('userid');
            var classId = btn.data('classid'); // Passed from search button

            btn.prop('disabled', true).text('Inscribiendo...');

            require(['core/ajax'], function (ajax) {
                var promises = ajax.call([{
                    methodname: 'local_grupomakro_manual_enroll',
                    args: { classId: classId, userId: userId }
                }]);

                promises[0].done(function (response) {
                    if (response.status === 'ok') {
                        btn.removeClass('btn-success').addClass('btn-secondary').text('Inscrito');
                        alert("Usuario inscrito correctamente.");
                    } else {
                        btn.prop('disabled', false).text('Inscribir'); // Reset
                        alert("Error: " + response.message);
                    }
                }).fail(function (ex) {
                    btn.prop('disabled', false).text('Inscribir');
                    alert("Error de sistema: " + ex.message);
                });
            });
        });

    });
});
