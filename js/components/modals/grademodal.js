Vue.component('grademodal', {
    template: `
        <div>
            <v-dialog
              v-model="dialog"
              persistent
              max-width="800"
            >
                <v-card class="rounded-lg overflow-hidden">
                    <v-card-title class="headline primary white--text d-flex align-center py-3 px-4">
                        <span>{{ lang.grades }}</span>
                        <v-spacer></v-spacer>
                        <v-btn icon dark @click="close">
                            <v-icon>mdi-close</v-icon>
                        </v-btn>
                    </v-card-title>

                    <v-card-text class="pa-4">
                        <div class="d-flex align-center mb-4">
                            <v-avatar color="primary lighten-4" size="48" class="mr-3">
                                <v-icon color="primary">mdi-account</v-icon>
                            </v-avatar>
                            <div>
                                <div class="text-h6 font-weight-bold">{{ studentName }}</div>
                                <div class="text-caption grey--text text--darken-1">{{ studentEmail }}</div>
                            </div>
                        </div>

                        <div v-if="!classId" class="d-flex justify-center mb-4">
                            <v-btn-toggle v-model="creditView" mandatory dense color="primary">
                                <v-btn :value="false" small>
                                    <v-icon left small>mdi-book-open-page-variant</v-icon>Pensum
                                </v-btn>
                                <v-btn :value="true" small>
                                    <v-icon left small>mdi-counter</v-icon>Créditos
                                </v-btn>
                            </v-btn-toggle>
                        </div>

                        <div v-if="!classId && creditView" class="mb-2">
                            <div class="d-flex align-center flex-wrap mb-3" style="gap: 16px;">
                                <div class="d-flex align-center">
                                    <span class="text-caption grey--text text--darken-1 mr-2">Alcance:</span>
                                    <v-btn-toggle v-model="creditScope" mandatory dense>
                                        <v-btn small value="all">Todas</v-btn>
                                        <v-btn small value="enrolled">Solo cursadas</v-btn>
                                    </v-btn-toggle>
                                </div>
                                <div v-if="creditPlanOptions.length > 2" class="d-flex align-center">
                                    <span class="text-caption grey--text text--darken-1 mr-2">Plan:</span>
                                    <v-select
                                        v-model="creditPlanId"
                                        :items="creditPlanOptions"
                                        item-text="text"
                                        item-value="value"
                                        dense
                                        hide-details
                                        outlined
                                        style="max-width: 280px;"
                                    ></v-select>
                                </div>
                            </div>

                            <div v-if="loadingCreditReport" class="text-center py-4">
                                <v-progress-circular indeterminate color="primary"></v-progress-circular>
                                <div class="caption grey--text mt-2">Cargando informe de créditos...</div>
                            </div>

                            <template v-else>
                                <div v-if="!creditReport || !creditReport.careers || creditReport.careers.length === 0" class="text-center py-6 grey--text">
                                    <v-icon large color="grey lighten-2">mdi-database-off</v-icon>
                                    <div class="mt-2 text-body-2 font-italic">No hay asignaturas para mostrar con el alcance seleccionado.</div>
                                </div>

                                <div v-for="(career, ci) in (creditReport ? creditReport.careers : [])" :key="ci" class="mb-6">
                                    <div class="d-flex align-center mb-2 px-2 py-1 blue darken-4 rounded white--text">
                                        <v-icon small color="white" class="mr-2">mdi-school</v-icon>
                                        <span class="font-weight-bold text-subtitle-1">{{ career.career }}</span>
                                    </div>

                                    <div v-for="(cuatri, qi) in career.cuatrimestres" :key="qi" class="mb-4 ml-1">
                                        <div class="d-flex align-center px-2 py-1 blue lighten-5 rounded mb-1">
                                            <span class="text-subtitle-2 font-weight-bold blue--text text--darken-3">{{ cuatri.name }}</span>
                                            <v-spacer></v-spacer>
                                            <span class="text-caption blue--text text--darken-3 font-weight-medium">Créditos: {{ cuatri.subtotal.approved }} / {{ cuatri.subtotal.total }}</span>
                                        </div>
                                        <v-simple-table dense class="elevation-1 rounded">
                                            <template v-slot:default>
                                                <thead>
                                                    <tr class="blue-grey lighten-5">
                                                        <th class="text-left py-2" style="width:55%">Asignatura</th>
                                                        <th class="text-center py-2">Créditos</th>
                                                        <th class="text-center py-2">Estado</th>
                                                        <th class="text-right py-2">Nota</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr v-for="(course, idx) in cuatri.courses" :key="idx">
                                                        <td class="text-body-2 py-2">
                                                            {{ course.coursename }}
                                                            <v-chip v-if="course.is_module" x-small color="teal darken-1" dark label class="ml-1" style="height:14px;font-size:9px;">M</v-chip>
                                                        </td>
                                                        <td class="text-center font-weight-medium">{{ course.credits }}</td>
                                                        <td class="text-center">
                                                            <v-chip x-small :color="course.statusColor" dark label class="font-weight-bold">{{ course.statusLabel }}</v-chip>
                                                        </td>
                                                        <td class="text-right font-weight-bold" :class="getGradeColor(course.grade)">{{ formatGrade(course.grade) }}</td>
                                                    </tr>
                                                    <tr class="grey lighten-3">
                                                        <td class="text-right font-weight-bold py-1">Subtotal cuatrimestre</td>
                                                        <td class="text-center font-weight-bold py-1">{{ cuatri.subtotal.total }}</td>
                                                        <td class="text-center font-weight-bold py-1" colspan="2">Aprobados: {{ cuatri.subtotal.approved }}</td>
                                                    </tr>
                                                </tbody>
                                            </template>
                                        </v-simple-table>
                                    </div>

                                    <div class="d-flex flex-wrap align-center mt-2 pa-2 rounded blue darken-3 white--text">
                                        <span class="text-caption font-weight-bold mr-3">RESUMEN</span>
                                        <span class="text-caption mr-3">Aprobados: <b>{{ career.summary.approved }}</b></span>
                                        <span class="text-caption mr-3">En curso: <b>{{ career.summary.incourse }}</b></span>
                                        <span class="text-caption mr-3">Pendientes: <b>{{ career.summary.pending }}</b></span>
                                        <span class="text-caption mr-3">Total: <b>{{ career.summary.total }}</b></span>
                                        <span class="text-caption">Avance: <b>{{ career.summary.pct }}%</b></span>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <div v-if="classId && (loadingGradebook || gradebook.length > 0)" class="mb-4">
                            <div class="d-flex align-center mb-3 px-2 py-1 blue darken-4 rounded white--text">
                                <v-icon small color="white" class="mr-2">mdi-book-open-variant</v-icon>
                                <span class="font-weight-bold text-subtitle-1">Libro de Calificaciones</span>
                            </div>

                            <div v-if="loadingGradebook" class="text-center py-4">
                                <v-progress-circular indeterminate color="primary"></v-progress-circular>
                                <div class="caption grey--text mt-2">Cargando calificaciones...</div>
                            </div>

                            <template v-else>
                                <div v-for="(catGroup, gIdx) in gradebook" :key="gIdx" class="mb-3">
                                    <div class="d-flex align-center px-2 py-1 blue-grey lighten-4 rounded mb-1">
                                        <v-icon x-small color="blue-grey darken-2" class="mr-1">mdi-folder-outline</v-icon>
                                        <span class="text-caption font-weight-bold text-uppercase blue-grey--text text--darken-2">
                                            {{ catGroup.category }}
                                        </span>
                                    </div>
                                    <v-simple-table dense class="elevation-1 rounded">
                                        <template v-slot:default>
                                            <thead>
                                                <tr class="blue-grey lighten-5">
                                                    <th class="text-left py-2" style="width:58%">Actividad</th>
                                                    <th class="text-right py-2">Ponderación</th>
                                                    <th class="text-right py-2">Nota</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="(item, idx) in catGroup.items" :key="idx">
                                                    <td class="text-body-2 py-2">{{ item.name }}</td>
                                                    <td class="text-right py-2 caption grey--text">
                                                        {{ item.weight_pct > 0 ? item.weight_pct.toFixed(1) + '%' : '--' }}
                                                    </td>
                                                    <td class="text-right font-weight-bold py-2"
                                                        :class="item.grade !== null ? getGradeColor(item.grade_pct) : 'grey--text'">
                                                        {{ item.grade !== null ? item.grade : 'Sin calificar' }}
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </template>
                                    </v-simple-table>
                                </div>

                                <div class="d-flex justify-end align-center mt-2 pa-2 rounded blue darken-4">
                                    <span class="white--text text-body-2 mr-3 font-weight-medium">Nota Final:</span>
                                    <span class="text-h6 font-weight-bold"
                                          :class="gradebookFinalGrade !== null ? (gradebookFinalGrade >= 70 ? 'light-green--text text--lighten-3' : 'red--text text--lighten-3') : 'white--text'">
                                        {{ gradebookFinalGrade !== null ? gradebookFinalGrade.toFixed(1) : '--' }}
                                    </span>
                                </div>
                            </template>
                        </div>

                        <div class="grade-content" v-if="!classId && !creditView">
                            <div v-if="loadingPensum" class="text-center py-4">
                                <v-progress-circular indeterminate color="primary"></v-progress-circular>
                                <div class="caption grey--text mt-2">Cargando pensum...</div>
                            </div>

                            <div v-else-if="careersList.length === 0" class="text-center py-6 grey--text">
                                <v-icon large color="grey lighten-2">mdi-database-off</v-icon>
                                <div class="mt-2 text-body-2 font-italic">No se encontraron planes de estudio para este estudiante.</div>
                            </div>

                            <div v-for="(career, careerIndex) in careersList" :key="careerIndex" class="mb-6">
                                <div class="d-flex align-center mb-2 px-2 py-1 grey lighten-4 rounded">
                                    <v-icon small color="primary" class="mr-2">mdi-school</v-icon>
                                    <span class="font-weight-bold text-subtitle-1 primary--text">
                                        {{ career.career }}
                                    </span>
                                </div>

                                <div v-if="!career.periods">
                                    <v-progress-linear indeterminate color="primary" class="mt-2"></v-progress-linear>
                                </div>

                                <div v-else-if="Object.keys(career.periods).length === 0" class="text-center py-6 grey--text">
                                    <v-icon large color="grey lighten-2">mdi-database-off</v-icon>
                                    <div class="mt-2 text-body-2 font-italic">No se encontraron asignaturas asociadas a este plan de estudios.</div>
                                </div>

                                <div v-else v-for="(courses, periodName) in career.periods" :key="periodName" class="period-group mb-4 ml-2">
                                    <div class="period-header d-flex align-center mb-2">
                                        <div class="period-line border-left pl-3" style="border-left: 3px solid #1976D2 !important;">
                                            <span class="text-subtitle-2 font-weight-bold text-uppercase grey--text text--darken-2">
                                                {{ periodName }}
                                            </span>
                                        </div>
                                    </div>

                                    <v-simple-table dense class="elevation-0 transparent">
                                        <template v-slot:default>
                                            <thead>
                                                <tr>
                                                    <th class="text-left text-overline" style="width: 52%">Asignatura</th>
                                                    <th class="text-center text-overline">Estado</th>
                                                    <th class="text-right text-overline">Nota</th>
                                                    <th class="text-center text-overline">Acción</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="(course, courseIndex) in courses" :key="courseIndex" class="course-row" @click="navigateToGradeReport(course)" style="cursor: pointer;">
                                                    <td class="py-2">
                                                        <div class="text-body-2 font-weight-medium text-wrap pr-2" style="line-height: 1.2;">
                                                            {{ course.coursename }}
                                                        </div>
                                                        <v-chip v-if="course.is_module" x-small color="teal darken-1" dark label class="mt-1" style="height:15px;font-size:10px;letter-spacing:0.3px;">
                                                            <v-icon style="font-size:10px;" class="mr-1">mdi-book-education-outline</v-icon>Módulo
                                                        </v-chip>
                                                        <v-chip v-if="revalidationFor(course)" x-small dark label
                                                            :color="revalidChipColor(revalidationFor(course))"
                                                            class="mt-1 ml-1" style="height:15px;font-size:10px;letter-spacing:0.3px;"
                                                            :title="revalidTooltip(revalidationFor(course))">
                                                            <v-icon style="font-size:10px;" class="mr-1">mdi-school-outline</v-icon>{{ revalidChipLabel(revalidationFor(course)) }}
                                                        </v-chip>
                                                        <v-chip v-if="homologationFor(course)" x-small dark label
                                                            :color="homologationChipColor(homologationFor(course).homologation_type)"
                                                            class="mt-1 ml-1" style="height:15px;font-size:10px;letter-spacing:0.3px;"
                                                            :title="homologationTooltip(homologationFor(course))">
                                                            <v-icon style="font-size:10px;" class="mr-1">mdi-check-decagram</v-icon>{{ homologationChipLabel(homologationFor(course).homologation_type) }}
                                                        </v-chip>
                                                    </td>
                                                    <td class="text-center">
                                                        <v-chip x-small :color="course.statusColor" dark label class="text-caption font-weight-bold">
                                                            {{ course.statusLabel }}
                                                        </v-chip>
                                                    </td>
                                                    <td class="text-right font-weight-bold" :class="getGradeColor(course.grade)">
                                                        {{ course.grade }}
                                                    </td>
                                                    <td class="text-center py-1" style="white-space:nowrap;">
                                                        <v-btn
                                                            v-if="canWithdrawFromCourse(course)"
                                                            x-small
                                                            color="error"
                                                            :loading="withdrawingCourseKey === getCourseKey(course)"
                                                            :disabled="!!withdrawingCourseKey"
                                                            @click.stop="withdrawFromCourse(course)"
                                                        >
                                                            Retirar
                                                        </v-btn>
                                                        <v-btn
                                                            v-else
                                                            x-small
                                                            color="primary"
                                                            :disabled="!canEnrollInCourse(course)"
                                                            @click.stop="openEnrollDialog(course)"
                                                        >
                                                            Inscribir
                                                        </v-btn>
                                                        <v-btn
                                                            v-if="Number(course.courseid || 0) > 0 && canEnrollInModule(course)"
                                                            x-small
                                                            :color="moduleStatusMap[getCourseKey(course)] ? 'teal lighten-1' : 'teal darken-2'"
                                                            dark
                                                            :loading="enrollingModuleKey === getCourseKey(course)"
                                                            :disabled="!!enrollingModuleKey || !!withdrawingCourseKey || !canEnrollInModule(course)"
                                                            @click.stop="enrollInModule(course)"
                                                            class="ml-1"
                                                            title="Inscribir en módulo independiente"
                                                        >
                                                            <v-icon x-small :left="!!moduleStatusMap[getCourseKey(course)]">mdi-book-education-outline</v-icon>
                                                            <span v-if="moduleStatusMap[getCourseKey(course)]">Módulo ✓</span>
                                                        </v-btn>
                                                        <v-btn
                                                            v-if="Number(course.courseid || 0) > 0 && canHomologate(course)"
                                                            x-small
                                                            color="deep-purple darken-2"
                                                            dark
                                                            :loading="homologatingCourseKey === getCourseKey(course)"
                                                            :disabled="!!homologatingCourseKey"
                                                            @click.stop="openHomologateDialog(course)"
                                                            class="ml-1"
                                                            title="Homologar nota (Suficiencia · Migración · Homologación)"
                                                        >
                                                            <v-icon x-small>mdi-check-decagram</v-icon>
                                                        </v-btn>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </template>
                                    </v-simple-table>
                                </div>
                            </div>
                        </div>
                    </v-card-text>

                    <v-divider class="my-0"></v-divider>

                    <v-card-actions class="pa-3">
                      <v-btn
                        v-if="!classId && creditView"
                        color="blue darken-3"
                        text
                        :disabled="loadingCreditReport"
                        @click="downloadCreditReport('pdf')"
                      >
                        <v-icon left>mdi-file-pdf-box</v-icon>
                        Créditos PDF
                      </v-btn>
                      <v-btn
                        v-if="!classId && creditView"
                        color="green darken-2"
                        text
                        :disabled="loadingCreditReport"
                        @click="downloadCreditReport('xlsx')"
                      >
                        <v-icon left>mdi-file-excel-box</v-icon>
                        Créditos Excel
                      </v-btn>
                      <v-btn
                        v-if="showSchedulePdfButton && !creditView"
                        color="secondary"
                        text
                        :loading="exportingSchedulePdf"
                        :disabled="exportingSchedulePdf || !(dataStudent && dataStudent.id)"
                        @click="downloadStudentSchedulePdf"
                      >
                        <v-icon left>mdi-file-pdf-box</v-icon>
                        Descargar horario PDF
                      </v-btn>
                      <v-btn
                        v-if="canExportGradesPdf && !creditView"
                        color="teal darken-2"
                        text
                        :loading="exportingGradesPdf"
                        :disabled="exportingGradesPdf || exportingDetailedGradesPdf"
                        @click="downloadGradesPdf"
                      >
                        <v-icon left>mdi-file-table-box</v-icon>
                        Exportar notas PDF
                      </v-btn>
                      <v-btn
                        v-if="canExportDetailedPdf && !creditView"
                        color="deep-purple darken-1"
                        text
                        :loading="exportingDetailedGradesPdf"
                        :disabled="exportingDetailedGradesPdf || exportingGradesPdf"
                        @click="downloadDetailedGradesPdf"
                      >
                        <v-icon left>mdi-file-chart</v-icon>
                        Detalle completo PDF
                      </v-btn>
                      <v-spacer></v-spacer>
                      <v-btn color="primary" text font-weight-bold @click="close">
                        <v-icon left>mdi-check</v-icon>
                        {{ lang.close }}
                      </v-btn>
                    </v-card-actions>
                  </v-card>
            </v-dialog>

            <v-dialog v-model="enrollDialog" max-width="780">
                <v-card class="rounded-lg overflow-hidden">
                    <v-card-title class="headline primary white--text d-flex align-center py-3 px-4">
                        <span>Inscribir en curso activo</span>
                        <v-spacer></v-spacer>
                        <v-btn icon dark @click="closeEnrollDialog">
                            <v-icon>mdi-close</v-icon>
                        </v-btn>
                    </v-card-title>

                    <v-card-text class="pa-4">
                        <div class="mb-3">
                            <div class="text-body-1 font-weight-bold">{{ selectedCourseName }}</div>
                            <div class="text-caption grey--text text--darken-1">Seleccione el curso activo en el que desea inscribir al estudiante.</div>
                        </div>

                        <div v-if="loadingEnrollClasses" class="text-center py-4">
                            <v-progress-circular indeterminate color="primary"></v-progress-circular>
                            <div class="caption grey--text mt-2">Cargando cursos activos...</div>
                        </div>

                        <v-alert v-else-if="enrollClassesError" type="error" dense outlined class="mb-0">
                            {{ enrollClassesError }}
                        </v-alert>

                        <v-alert v-else-if="enrollableClasses.length === 0" type="info" dense outlined class="mb-0">
                            No hay cursos activos disponibles para esta asignatura.
                        </v-alert>

                        <v-simple-table v-else dense class="elevation-1 rounded">
                            <template v-slot:default>
                                <thead>
                                    <tr class="blue-grey lighten-5">
                                        <th class="text-left py-2">Curso</th>
                                        <th class="text-left py-2">Docente</th>
                                        <th class="text-left py-2">Horario</th>
                                        <th class="text-center py-2">Cupo</th>
                                        <th class="text-center py-2">Estado</th>
                                        <th class="text-center py-2">Accion</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in enrollableClasses" :key="item.id">
                                        <td class="py-2">
                                            <div class="text-body-2 font-weight-medium">{{ item.name }}</div>
                                            <div class="caption grey--text">{{ item.typelabel }}</div>
                                        </td>
                                        <td class="py-2">{{ item.instructorname || '--' }}</td>
                                        <td class="py-2">
                                            <div>{{ getClassDaysLabel(item.classdays) }}</div>
                                            <div class="caption grey--text">{{ item.inithourformatted || '--' }} - {{ item.endhourformatted || '--' }}</div>
                                            <div class="caption grey--text">{{ item.initdateformatted || '--' }} / {{ item.enddateformatted || '--' }}</div>
                                        </td>
                                        <td class="text-center py-2">
                                            <span :class="isOverCapacity(item) ? 'error--text font-weight-bold' : ''">
                                                {{ item.enrolled }} / {{ item.classroomcapacity || 0 }}
                                            </span>
                                        </td>
                                        <td class="text-center py-2">
                                            <v-chip
                                                x-small
                                                :color="item.alreadyenrolled ? 'info' : (isOverCapacity(item) ? 'warning' : 'success')"
                                                dark
                                                label
                                            >
                                                {{ item.alreadyenrolled ? 'Ya inscrito' : (isOverCapacity(item) ? 'Sobre cupo' : 'Disponible') }}
                                            </v-chip>
                                        </td>
                                        <td class="text-center py-2">
                                            <v-btn
                                                x-small
                                                color="primary"
                                                :loading="enrollingClassId === item.id"
                                                :disabled="item.alreadyenrolled || !!enrollingClassId"
                                                @click="enrollStudentInClass(item)"
                                            >
                                                Inscribir
                                            </v-btn>
                                        </td>
                                    </tr>
                                </tbody>
                            </template>
                        </v-simple-table>
                    </v-card-text>

                    <v-divider></v-divider>

                    <v-card-actions class="pa-3">
                        <v-spacer></v-spacer>
                        <v-btn color="primary" text @click="closeEnrollDialog">Cerrar</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>

            <v-dialog v-model="homologationDialog" max-width="600" persistent>
                <v-card class="rounded-lg overflow-hidden">
                    <v-card-title class="headline deep-purple darken-2 white--text d-flex align-center py-3 px-4">
                        <v-icon left dark>mdi-check-decagram</v-icon>
                        <span>Homologar nota</span>
                        <v-spacer></v-spacer>
                        <v-btn icon dark @click="closeHomologateDialog" :disabled="homologatingCourseKey">
                            <v-icon>mdi-close</v-icon>
                        </v-btn>
                    </v-card-title>

                    <v-card-text class="pa-4">
                        <div v-if="homologationSelected" class="mb-3">
                            <div class="text-body-1 font-weight-bold">{{ studentName }}</div>
                            <div class="text-caption grey--text text--darken-1">
                                {{ homologationSelected.coursename }}
                                <span v-if="homologationSelected.statusLabel" class="ml-1">
                                    · Estado actual: <b>{{ homologationSelected.statusLabel }}</b>
                                </span>
                            </div>
                        </div>

                        <div class="text-caption font-weight-bold grey--text text--darken-2 mb-1">
                            Tipo de homologación <span class="red--text">*</span>
                        </div>
                        <v-radio-group v-model="homologationForm.type" mandatory class="mt-0 mb-3" hide-details>
                            <v-radio value="suficiencia"   color="indigo darken-2"   class="mb-1">
                                <template v-slot:label>
                                    <span class="text-body-2">Examen de suficiencia</span>
                                </template>
                            </v-radio>
                            <v-radio value="migracion"     color="amber darken-3"    class="mb-1">
                                <template v-slot:label>
                                    <span class="text-body-2">Migración</span>
                                </template>
                            </v-radio>
                            <v-radio value="homologacion"  color="deep-purple darken-2" class="mb-1">
                                <template v-slot:label>
                                    <span class="text-body-2">Homologación</span>
                                </template>
                            </v-radio>
                        </v-radio-group>

                        <v-text-field
                            v-model.number="homologationForm.grade"
                            label="Nota homologada (0-100)"
                            type="number"
                            min="0"
                            max="100"
                            step="0.1"
                            suffix="pts"
                            :error="!!homologationGradeError"
                            :error-messages="homologationGradeError ? [homologationGradeError] : []"
                            outlined
                            dense
                            class="mb-2"
                            required
                        ></v-text-field>

                        <v-textarea
                            v-model="homologationForm.observation"
                            label="Observación / motivo"
                            placeholder="Ej: Estudiante aprobó examen de suficiencia el 12/06/2026 (acta 2026-014)."
                            rows="3"
                            auto-grow
                            :error="!!homologationObservationError"
                            :error-messages="homologationObservationError ? [homologationObservationError] : []"
                            outlined
                            dense
                            class="mb-2"
                            required
                        ></v-textarea>

                        <v-alert type="info" dense outlined class="mb-0">
                            <div class="text-caption">
                                La nota se registrará en el ítem <b>Nota Final Integrada</b> del curso y actualizará el estado
                                (Aprobada si la nota es ≥ 71, Reprobada si es &lt; 71). Si el estudiante aún no estaba
                                inscrito en el curso, se inscribirá automáticamente <b>sin</b> asociarlo a ningún grupo.
                            </div>
                        </v-alert>
                    </v-card-text>

                    <v-divider></v-divider>

                    <v-card-actions class="pa-3">
                        <v-spacer></v-spacer>
                        <v-btn text :disabled="homologatingCourseKey" @click="closeHomologateDialog">
                            Cancelar
                        </v-btn>
                        <v-btn color="deep-purple darken-2" dark
                               :loading="homologatingCourseKey"
                               :disabled="homologatingCourseKey || !isHomologationFormValid"
                               @click="homologate(homologationSelected)">
                            <v-icon left>mdi-check-decagram</v-icon>
                            Homologar
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        </div>
    `,
    data() {
        return {
            dialog: false,
            gradebook: [],
            gradebookCourseGrade: null,
            loadingGradebook: false,
            loadingPensum: false,
            enrollDialog: false,
            loadingEnrollClasses: false,
            enrollingClassId: null,
            enrollClasses: [],
            enrollClassesError: '',
            selectedCourse: null,
            withdrawingCourseKey: null,
            exportingSchedulePdf: false,
            exportingGradesPdf: false,
            exportingDetailedGradesPdf: false,
            enrollingModuleKey: null,
            moduleStatusMap: {},
            creditView: false,
            loadingCreditReport: false,
            creditReport: null,
            creditScope: 'all',
            creditPlanId: 0,
            revalidations: {},   // keyed by corecourseid
            homologations: {},    // keyed by corecourseid (mirrors gmk_course_progre.homologation_*)
            homologatingCourseKey: null,
            homologationDialog: false,
            homologationSelected: null,
            homologationForm: {
                type: 'homologacion',
                grade: 71,
                observation: ''
            },
            homologationTypeOptions: [
                { value: 'suficiencia',   label: 'Examen de suficiencia' },
                { value: 'migracion',     label: 'Migración' },
                { value: 'homologacion',  label: 'Homologación' }
            ]
        };
    },
    watch: {
        creditView(val) {
            if (val && !this.creditReport && !this.loadingCreditReport) {
                this.fetchCreditReport();
            }
        },
        creditScope() {
            if (this.creditView) {
                this.fetchCreditReport();
            }
        },
        creditPlanId() {
            if (this.creditView) {
                this.fetchCreditReport();
            }
        }
    },
    props: {
        dataStudent: Object,
        classId: [Number, String]
    },
    created() {
        this.dialog = true;
        if (this.classId) {
            this.fetchGradebook();
        } else {
            this.getpensum();
        }
        this.fetchRevalidations();
    },
    methods: {
        getGradeColor(grade) {
            const val = parseFloat(grade);
            if (isNaN(val)) return 'grey--text';
            return val >= 70 ? 'success--text' : 'error--text';
        },
        formatGrade(grade) {
            const raw = String(grade == null ? '' : grade).trim();
            if (raw === '' || raw === '-' || raw === '--') return '--';
            return raw;
        },
        async fetchRevalidations() {
            if (!this.dataStudent || !this.dataStudent.id) return;
            try {
                const url = window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php');
                const response = await window.axios.get(url, {
                    params: {
                        action: 'local_grupomakro_get_revalidations_for_user',
                        sesskey: M.cfg.sesskey,
                        userId: Number(this.dataStudent.id),
                    }
                });
                const payload = response && response.data ? response.data : {};
                if (payload.status === 'success' && Array.isArray(payload.data)) {
                    const map = {};
                    payload.data.forEach(r => { map[Number(r.corecourseid)] = r; });
                    this.revalidations = map;
                }
            } catch (error) {
                // Director-only data (requires site:config). Silently ignore for other roles.
                console.debug('fetchRevalidations skipped:', error && error.message);
            }
        },
        revalidationFor(course) {
            const cid = Number(course && course.courseid ? course.courseid : 0);
            return cid && this.revalidations[cid] ? this.revalidations[cid] : null;
        },
        revalidChipColor(rev) {
            if (!rev) return 'grey';
            if (rev.result === 'approved') return 'green';
            if (rev.result === 'failed') return 'red';
            return 'amber darken-2';
        },
        revalidChipLabel(rev) {
            if (!rev) return '';
            if (rev.result === 'approved') return 'Aprobó reválida (71)';
            if (rev.result === 'failed') return 'Reprobó reválida';
            return 'Reválida programada';
        },
        revalidTooltip(rev) {
            if (!rev) return '';
            const orig = (rev.originalgrade != null) ? Number(rev.originalgrade).toFixed(1) : '--';
            const rg = (rev.revalidgrade != null) ? Number(rev.revalidgrade).toFixed(1) : '--';
            const pay = rev.payment_state === 'paid' ? 'Factura pagada' : 'Factura sin pagar';
            return `Nota original: ${orig} · Nota reválida: ${rg} · ${pay}`
                + (rev.invoice_number ? ` · Factura ${rev.invoice_number}` : '');
        },
        homologationFor(course) {
            const cid = Number(course && course.courseid ? course.courseid : 0);
            if (!cid) return null;
            if (this.homologations && this.homologations[cid]) return this.homologations[cid];
            // Fallback to backend-rendered metadata so the chip survives a stale cache.
            if (course.homologation_type) {
                return {
                    homologation_type: course.homologation_type,
                    homologation_note: course.homologation_note || '',
                    homologation_at: course.homologation_at || 0,
                    homologation_by: course.homologation_by || 0
                };
            }
            return null;
        },
        homologationChipColor(type) {
            switch (type) {
                case 'suficiencia':  return 'indigo darken-2';
                case 'migracion':    return 'amber darken-3';
                case 'homologacion': return 'deep-purple darken-2';
                default:             return 'grey darken-2';
            }
        },
        homologationChipLabel(type) {
            switch (type) {
                case 'suficiencia':  return 'Homologada · Suficiencia';
                case 'migracion':    return 'Homologada · Migración';
                case 'homologacion': return 'Homologada · Homologación';
                default:             return 'Homologada';
            }
        },
        homologationTooltip(h) {
            if (!h) return '';
            const pieces = [];
            pieces.push('Tipo: ' + this.homologationChipLabel(h.homologation_type));
            if (h.homologation_at) {
                pieces.push('Aplicada: ' + new Date(Number(h.homologation_at) * 1000).toLocaleString('es-PA'));
            }
            if (h.homologation_note) {
                pieces.push('Motivo: ' + h.homologation_note);
            }
            return pieces.join(' · ');
        },
        canHomologate(course) {
            // Site admins only (same restriction as the rest of the modal).
            const flag = window.isAdmin;
            const isAdmin = (flag === true) || (flag === 'true') || (flag === 1) || (flag === '1');
            if (!isAdmin) return false;
            return Number(course && course.courseid ? course.courseid : 0) > 0
                && Number(course && course.learningplanid ? course.learningplanid : 0) > 0;
        },
        openHomologateDialog(course) {
            if (!this.canHomologate(course)) return;
            this.homologationSelected = course;
            const existing = this.homologationFor(course);
            this.homologationForm = {
                type: existing ? existing.homologation_type : 'homologacion',
                grade: existing ? Number(course.grade || 71) : 71,
                observation: existing ? (existing.homologation_note || '') : ''
            };
            this.homologationDialog = true;
        },
        closeHomologateDialog() {
            if (this.homologatingCourseKey) return;
            this.homologationDialog = false;
            this.homologationSelected = null;
        },
        async homologate(course) {
            if (!course || this.homologatingCourseKey) return;

            const gradeErr = this.homologationGradeError;
            const obsErr   = this.homologationObservationError;
            if (gradeErr || obsErr) {
                this.showMessage('warning', 'Revisa los campos obligatorios antes de continuar.');
                return;
            }
            if (!this.isHomologationFormValid) return;

            const gradeVal = Number(this.homologationForm.grade);
            const typeLabel = this.homologationChipLabel(this.homologationForm.type);
            const studentName = this.studentName;
            const courseName = course.coursename || 'esta asignatura';

            const confirmed = await window.Swal.fire({
                icon: 'question',
                title: '¿Confirmar homologación?',
                html:
                    `<div style="text-align:left;">` +
                    `<p><b>${studentName}</b> · <b>${courseName}</b></p>` +
                    `<p>Tipo: <b>${typeLabel}</b><br>Nota: <b>${gradeVal.toFixed(1)}</b></p>` +
                    `<p style="margin-top:6px;"><b>Motivo:</b><br><i>${(this.homologationForm.observation || '').replace(/</g, '&lt;')}</i></p>` +
                    `<p style="margin-top:6px;font-size:12px;" class="grey--text">` +
                    `La nota se registrará en "Nota Final Integrada" y actualizará el estado del curso. ` +
                    (Number(course.progressclassid || 0) > 0 || Number(course.module_classid || 0) > 0
                        ? ''
                        : 'Si el estudiante no está inscrito, será inscrito automáticamente sin grupo.') +
                    `</p></div>`,
                showCancelButton: true,
                confirmButtonText: 'Sí, homologar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#4527A0',
            });
            if (!confirmed.isConfirmed) return;

            this.homologatingCourseKey = this.getCourseKey(course);
            try {
                const url = window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php');
                const response = await window.axios.get(url, {
                    params: {
                        action:         'local_grupomakro_homologate_course_grade',
                        sesskey:        M.cfg.sesskey,
                        userId:         Number(this.dataStudent.id),
                        learningPlanId: Number(course.learningplanid || 0),
                        coreCourseId:   Number(course.courseid || 0),
                        grade:          gradeVal,
                        type:           String(this.homologationForm.type || 'homologacion'),
                        observation:    String(this.homologationForm.observation || '')
                    }
                });
                const payload = (response && response.data) || {};
                const result  = (payload.status === 'success' && payload.data) ? payload.data : payload;

                if (result.status === 'ok') {
                    const cid = Number(course.courseid || 0);
                    if (cid > 0) {
                        this.$set(this.homologations, cid, {
                            homologation_type: result.homologation_type || this.homologationForm.type,
                            homologation_note: String(this.homologationForm.observation || ''),
                            homologation_at:   Number(result.homologation_at || Math.floor(Date.now() / 1000)),
                            homologation_by:   Number(result.homologation_by || 0)
                        });
                    }
                    this.showMessage('success',
                        result.message || 'Nota homologada correctamente.');
                    this.closeHomologateDialog();
                    // Refresh pensum so grade / status chip updates.
                    await this.getpensum();
                } else {
                    this.showMessage('error', result.message || 'No se pudo homologar la nota.');
                }
            } catch (error) {
                console.error('Error homologating course grade:', error);
                const msg = (error && error.response && error.response.data && error.response.data.data && error.response.data.data.message)
                    || (error && error.message)
                    || 'Error al homologar la nota.';
                this.showMessage('error', msg);
            } finally {
                this.homologatingCourseKey = null;
            }
        },
        async fetchCreditReport() {
            if (!this.dataStudent || !this.dataStudent.id) return;
            this.loadingCreditReport = true;
            try {
                const url = window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php');
                const response = await window.axios.get(url, {
                    params: {
                        action: 'local_grupomakro_get_credit_report',
                        sesskey: M.cfg.sesskey,
                        userId: Number(this.dataStudent.id),
                        scope: this.creditScope,
                        planId: Number(this.creditPlanId) || 0,
                    }
                });
                const payload = response && response.data ? response.data : {};
                if (payload.status === 'success' && payload.data) {
                    this.creditReport = payload.data;
                } else {
                    this.creditReport = { careers: [] };
                }
            } catch (error) {
                console.error('Error fetching credit report:', error);
                this.creditReport = { careers: [] };
            } finally {
                this.loadingCreditReport = false;
            }
        },
        downloadCreditReport(format) {
            if (!this.dataStudent || !this.dataStudent.id) return;
            const base = window.location.origin + '/local/grupomakro_core/pages/credit_report.php';
            const params = new URLSearchParams({
                userId: String(this.dataStudent.id),
                scope: this.creditScope,
                planId: String(Number(this.creditPlanId) || 0),
                format: format === 'xlsx' ? 'xlsx' : 'pdf',
                sesskey: M.cfg.sesskey,
            });
            window.open(base + '?' + params.toString(), '_blank');
        },
        navigateToGradeReport(item) {
            const gradebookUrl = `/grade/report/grader/index.php?id=${item.courseid}`;
            window.location = gradebookUrl;
        },
        close() {
            this.enrollDialog = false;
            this.dialog = false;
            this.$emit('close-dialog');
        },
        async fetchGradebook() {
            this.loadingGradebook = true;
            this.gradebook = [];
            this.gradebookCourseGrade = null;
            try {
                const url = window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php');
                const response = await window.axios.get(url, {
                    params: {
                        action: 'local_grupomakro_get_student_gradebook',
                        sesskey: M.cfg.sesskey,
                        userId: this.dataStudent.id,
                        classId: this.classId,
                    }
                });
                const payload = response && response.data ? response.data : {};
                if (payload.status === 'success' && payload.data) {
                    const raw = payload.data.gradebook;
                    this.gradebook = typeof raw === 'string' ? JSON.parse(raw) : (raw || []);
                    const cg = payload.data.course_grade;
                    this.gradebookCourseGrade = (cg !== null && cg !== undefined) ? Number(cg) : null;
                }
            } catch (error) {
                console.error('Error fetching gradebook:', error);
            } finally {
                this.loadingGradebook = false;
            }
        },
        loadExternalScript(src, options = {}) {
            return new Promise((resolve, reject) => {
                const isolateAmd = !!options.isolateAmd;
                let originalDefine = null;
                let originalRequire = null;
                const restoreAmd = () => {
                    if (isolateAmd && originalDefine) {
                        window.define = originalDefine;
                        if (originalRequire) {
                            window.require = originalRequire;
                        }
                    }
                };

                const selector = `script[data-gmk-src="${src}"]`;
                const existing = document.querySelector(selector);
                if (existing) {
                    if (existing.getAttribute('data-loaded') === '1') {
                        resolve();
                        return;
                    }
                    existing.addEventListener('load', () => resolve(), { once: true });
                    existing.addEventListener('error', () => reject(new Error('Script load error: ' + src)), { once: true });
                    return;
                }

                if (isolateAmd && typeof window.define === 'function' && window.define.amd) {
                    originalDefine = window.define;
                    originalRequire = window.require;
                    window.define = undefined;
                }

                const script = document.createElement('script');
                script.src = src;
                script.async = true;
                script.setAttribute('data-gmk-src', src);
                script.addEventListener('load', () => {
                    script.setAttribute('data-loaded', '1');
                    restoreAmd();
                    resolve();
                }, { once: true });
                script.addEventListener('error', () => {
                    restoreAmd();
                    script.remove();
                    reject(new Error('Script load error: ' + src));
                }, { once: true });
                document.head.appendChild(script);
            });
        },
        async ensurePdfLibrary() {
            if ((window.jspdf && window.jspdf.jsPDF) || window.jsPDF) {
                return;
            }
            const sources = [
                'https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js',
                'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
            ];
            let lasterror = null;
            for (const src of sources) {
                try {
                    await this.loadExternalScript(src, { isolateAmd: true });
                    if ((window.jspdf && window.jspdf.jsPDF) || window.jsPDF) {
                        return;
                    }
                } catch (error) {
                    lasterror = error;
                }
            }
            if (lasterror) {
                throw lasterror;
            }
            throw new Error('No se pudo inicializar jsPDF.');
        },
        sanitizeFileToken(value) {
            const raw = String(value || '');
            const normalized = raw
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-zA-Z0-9_-]+/g, '_')
                .replace(/_+/g, '_')
                .replace(/^_+|_+$/g, '');
            return normalized || 'estudiante';
        },
        getSchedulePdfLogoUrl() {
            const raw = (typeof window.schedulePdfLogoUrl === 'string') ? window.schedulePdfLogoUrl.trim() : '';
            if (!raw) {
                return '';
            }
            try {
                const parsed = new URL(raw, window.location.origin);
                if (parsed.origin !== window.location.origin) {
                    return '';
                }
                return parsed.href;
            } catch (e) {
                return '';
            }
        },
        loadImageForPdf(url) {
            return new Promise((resolve, reject) => {
                const img = new Image();
                img.onload = () => resolve(img);
                img.onerror = () => reject(new Error('Image load error: ' + url));
                const hasQuery = url.indexOf('?') !== -1;
                img.src = `${url}${hasQuery ? '&' : '?'}v=${Date.now()}`;
            });
        },
        toDayIndex(dayValue) {
            if (typeof dayValue === 'number' && Number.isFinite(dayValue)) {
                const n = Math.trunc(dayValue);
                if (n >= 1 && n <= 7) return n;
                if (n === 0) return 7;
            }
            const raw = String(dayValue || '').trim();
            if (!raw) return 0;
            if (/^\d+$/.test(raw)) {
                const n = Number(raw);
                if (n >= 1 && n <= 7) return n;
                if (n === 0) return 7;
            }
            const key = raw
                .toLowerCase()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '');
            const map = {
                lun: 1, lunes: 1, monday: 1,
                mar: 2, martes: 2, tuesday: 2,
                mie: 3, miercoles: 3, wednesday: 3,
                jue: 4, jueves: 4, thursday: 4,
                vie: 5, viernes: 5, friday: 5,
                sab: 6, sabado: 6, saturday: 6,
                dom: 7, domingo: 7, sunday: 7,
            };
            return map[key] || 0;
        },
        toMinutes(timeValue) {
            const raw = String(timeValue || '').trim();
            if (!raw || raw === '--') {
                return null;
            }
            const normalized = raw
                .toLowerCase()
                .replace(/\./g, '')
                .replace(/\s+/g, '');

            let match = normalized.match(/^(\d{1,2}):(\d{2})(?::\d{2})?(am|pm)?$/);
            if (!match) {
                match = normalized.match(/^(\d{1,2})(am|pm)$/);
                if (match) {
                    match = [normalized, match[1], '00', match[2]];
                }
            }
            if (!match) {
                return null;
            }

            let hours = Number(match[1]);
            const minutes = Number(match[2]);
            const meridiem = match[3] || '';

            if (!Number.isFinite(hours) || !Number.isFinite(minutes) || minutes < 0 || minutes > 59) {
                return null;
            }

            if (meridiem === 'pm' && hours < 12) {
                hours += 12;
            } else if (meridiem === 'am' && hours === 12) {
                hours = 0;
            }

            if (hours < 0 || hours > 23) {
                return null;
            }
            return (hours * 60) + minutes;
        },
        formatMinutesLabel(totalMinutes) {
            const min = Math.max(0, Math.min(24 * 60, Number(totalMinutes) || 0));
            const h = Math.floor(min / 60);
            const m = min % 60;
            const hh = String(h).padStart(2, '0');
            const mm = String(m).padStart(2, '0');
            return `${hh}:${mm}`;
        },
        getCalendarColor(seed) {
            const palette = [
                [232, 245, 233],
                [227, 242, 253],
                [255, 243, 224],
                [243, 229, 245],
                [232, 234, 246],
                [252, 228, 236],
                [225, 245, 254],
                [255, 249, 196],
            ];
            const source = String(seed || '0');
            let hash = 0;
            for (let i = 0; i < source.length; i += 1) {
                hash = ((hash * 31) + source.charCodeAt(i)) % 2147483647;
            }
            return palette[Math.abs(hash) % palette.length];
        },
        extractCalendarEntries(classes) {
            const entries = [];
            const withoutSchedule = [];
            const unique = new Set();

            const pushEntry = (item, dayValue, startValue, endValue) => {
                const dayIndex = this.toDayIndex(dayValue);
                const startMin = this.toMinutes(startValue);
                let endMin = this.toMinutes(endValue);
                if (dayIndex < 1 || dayIndex > 7 || startMin === null || endMin === null) {
                    return false;
                }
                if (endMin <= startMin) {
                    endMin = startMin + 60;
                }
                endMin = Math.min(endMin, 24 * 60);
                const classId = Number(item && item.id ? item.id : 0);
                const key = `${classId}|${dayIndex}|${startMin}|${endMin}`;
                if (unique.has(key)) {
                    return false;
                }
                unique.add(key);
                entries.push({
                    classid: classId,
                    name: String(item && item.name ? item.name : '--'),
                    subjectname: String(item && item.subjectname ? item.subjectname : (item && item.name ? item.name : '--')),
                    instructorname: String(item && item.instructorname ? item.instructorname : ''),
                    classroomname: String(item && item.classroomname ? item.classroomname : 'Sin aula'),
                    enrollmentstatus: String(item && item.enrollmentstatus ? item.enrollmentstatus : 'Relacionado'),
                    periodid: Number(item && item.periodid ? item.periodid : 0),
                    periodname: String(item && item.periodname ? item.periodname : ''),
                    dayIndex: dayIndex,
                    startMin: startMin,
                    endMin: endMin,
                });
                return true;
            };

            classes.forEach((item) => {
                let addedAny = false;
                const structured = Array.isArray(item && item.schedules) ? item.schedules : [];

                structured.forEach((schedule) => {
                    const dayValue = (schedule && schedule.dayindex) ? schedule.dayindex : (schedule ? schedule.day : '');
                    const startValue = schedule ? schedule.start : '';
                    const endValue = schedule ? schedule.end : '';
                    if (pushEntry(item, dayValue, startValue, endValue)) {
                        addedAny = true;
                    }
                });

                if (!addedAny) {
                    const pieces = Array.isArray(item && item.schedulepieces) ? item.schedulepieces : [];
                    pieces.forEach((piece) => {
                        const text = String(piece || '').trim();
                        if (!text) {
                            return;
                        }
                        const match = text.match(/^(.+?)\s+([0-9]{1,2}:[0-9]{2}(?:\s*[ap]\.?m\.?)?)-([0-9]{1,2}:[0-9]{2}(?:\s*[ap]\.?m\.?)?)$/i);
                        if (!match) {
                            return;
                        }
                        const daysPart = String(match[1] || '');
                        const startValue = String(match[2] || '');
                        const endValue = String(match[3] || '');
                        const dayTokens = daysPart
                            .split(/[,/|]+/)
                            .map((d) => String(d || '').trim())
                            .filter(Boolean);

                        let pieceAdded = false;
                        dayTokens.forEach((dayToken) => {
                            if (pushEntry(item, dayToken, startValue, endValue)) {
                                pieceAdded = true;
                            }
                        });

                        if (!pieceAdded && pushEntry(item, daysPart, startValue, endValue)) {
                            pieceAdded = true;
                        }
                        if (pieceAdded) {
                            addedAny = true;
                        }
                    });
                }

                if (!addedAny) {
                    withoutSchedule.push(item);
                }
            });

            entries.sort((a, b) => {
                if (a.dayIndex !== b.dayIndex) return a.dayIndex - b.dayIndex;
                if (a.startMin !== b.startMin) return a.startMin - b.startMin;
                if (a.endMin !== b.endMin) return a.endMin - b.endMin;
                return a.classid - b.classid;
            });

            return { entries, withoutSchedule };
        },
        async downloadStudentSchedulePdf() {
            if (this.exportingSchedulePdf || !(this.dataStudent && this.dataStudent.id)) {
                return;
            }

            this.exportingSchedulePdf = true;
            try {
                const url = window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php');
                const params = {
                    action: 'local_grupomakro_get_student_schedule_pdf_data',
                    sesskey: M.cfg.sesskey,
                    userId: Number(this.dataStudent.id),
                };

                const response = await window.axios.get(url, { params });
                const payload = response && response.data ? response.data : {};
                if (payload.status !== 'success') {
                    this.showMessage('error', payload.message || 'No se pudo obtener el horario del estudiante.');
                    return;
                }

                const classes = Array.isArray(payload.classes) ? payload.classes : [];
                if (!classes.length) {
                    this.showMessage('info', 'El estudiante no tiene clases activas o pendientes para exportar.');
                    return;
                }

                await this.ensurePdfLibrary();
                const jsPDF = (window.jspdf && window.jspdf.jsPDF) ? window.jspdf.jsPDF : window.jsPDF;
                const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });

                const student = payload.student || {};
                const generatedAt = String(payload.generatedat || '');
                const studentIdentification = String(
                    (this.dataStudent && this.dataStudent.documentnumber) ||
                    student.documentnumber ||
                    (this.dataStudent && this.dataStudent.idnumber) ||
                    student.idnumber ||
                    '--'
                );
                const calendarData = this.extractCalendarEntries(classes);
                const entries = calendarData.entries;
                const withoutSchedule = calendarData.withoutSchedule;
                let logoImage = null;
                let logoRatio = 1;

                const logoUrl = this.getSchedulePdfLogoUrl();
                if (logoUrl) {
                    try {
                        logoImage = await this.loadImageForPdf(logoUrl);
                        if (logoImage && logoImage.naturalWidth > 0 && logoImage.naturalHeight > 0) {
                            logoRatio = logoImage.naturalWidth / logoImage.naturalHeight;
                        }
                    } catch (logoError) {
                        console.warn('Schedule PDF logo could not be loaded:', logoError);
                    }
                }

                if (!entries.length && !withoutSchedule.length) {
                    this.showMessage('info', 'No hay datos de horario para exportar.');
                    return;
                }

                const pageW = doc.internal.pageSize.getWidth();
                const pageH = doc.internal.pageSize.getHeight();
                const margin = 8;

                doc.setFillColor(25, 118, 210);
                doc.roundedRect(margin, margin, pageW - (margin * 2), 16, 2, 2, 'F');
                doc.setTextColor(255, 255, 255);
                let headerTextX = margin + 3;
                if (logoImage) {
                    const logoH = 12;
                    const logoW = Math.max(10, Math.min(36, logoH * logoRatio));
                    const logoX = margin + 2;
                    const logoY = margin + 2;
                    try {
                        doc.addImage(logoImage, 'PNG', logoX, logoY, logoW, logoH);
                        headerTextX = logoX + logoW + 2.5;
                    } catch (e1) {
                        try {
                            doc.addImage(logoImage, 'JPEG', logoX, logoY, logoW, logoH);
                            headerTextX = logoX + logoW + 2.5;
                        } catch (e2) {
                            // Continue without logo.
                        }
                    }
                }
                doc.setFont('helvetica', 'bold');
                doc.setFontSize(14);
                doc.text('Horario semanal del estudiante', headerTextX, margin + 6.5);
                doc.setFont('helvetica', 'normal');
                doc.setFontSize(9);
                doc.text('Generado: ' + (generatedAt || '--'), headerTextX, margin + 12);

                doc.setTextColor(0, 0, 0);
                doc.setFont('helvetica', 'bold');
                doc.setFontSize(9);
                doc.text('Estudiante:', margin, margin + 22);
                doc.setFont('helvetica', 'normal');
                doc.text(String(student.name || this.studentName || '--'), margin + 20, margin + 22);
                doc.setFont('helvetica', 'bold');
                doc.text('ID:', margin + 110, margin + 22);
                doc.setFont('helvetica', 'normal');
                doc.text(studentIdentification, margin + 117, margin + 22);
                doc.setFont('helvetica', 'bold');
                doc.text('Email:', margin + 170, margin + 22);
                doc.setFont('helvetica', 'normal');
                doc.text(String(student.email || this.studentEmail || '--'), margin + 182, margin + 22);

                const periodPalette = [
                    [21, 101, 192],
                    [46, 125, 50],
                    [230, 81, 0],
                    [123, 31, 162],
                    [0, 131, 143],
                    [183, 28, 28],
                    [84, 110, 122],
                ];
                const uniquePeriods = [...new Set(entries.map((e) => e.periodname).filter(Boolean))].sort();
                const periodColors = {};
                uniquePeriods.forEach((pname, i) => {
                    periodColors[pname] = periodPalette[i % periodPalette.length];
                });
                const defaultPeriodColor = [97, 97, 97];

                let minMinutes = 7 * 60;
                let maxMinutes = 22 * 60;
                if (entries.length > 0) {
                    minMinutes = Math.min(...entries.map((e) => e.startMin));
                    maxMinutes = Math.max(...entries.map((e) => e.endMin));
                    minMinutes = Math.max(0, (Math.floor(minMinutes / 30) * 30) - 30);
                    maxMinutes = Math.min(24 * 60, (Math.ceil(maxMinutes / 30) * 30) + 30);
                    if ((maxMinutes - minMinutes) < (5 * 60)) {
                        maxMinutes = Math.min(24 * 60, minMinutes + (5 * 60));
                    }
                }

                const interval = 30;
                const rows = Math.max(1, Math.round((maxMinutes - minMinutes) / interval));
                const dayLabels = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado', 'Domingo'];
                const calendarX = margin;
                const calendarY = margin + 26;
                const bottomReserve = withoutSchedule.length > 0 ? 20 : 12;
                const calendarW = pageW - (margin * 2);
                const calendarH = Math.max(90, pageH - calendarY - bottomReserve);
                const timeColumnW = 17;
                const dayHeaderH = 8;
                const dayW = (calendarW - timeColumnW) / 7;
                const rowH = (calendarH - dayHeaderH) / rows;

                doc.setDrawColor(180, 180, 180);
                doc.setLineWidth(0.2);
                doc.rect(calendarX, calendarY, calendarW, calendarH);
                doc.setFillColor(240, 244, 248);
                doc.rect(calendarX, calendarY, calendarW, dayHeaderH, 'F');
                doc.rect(calendarX, calendarY, timeColumnW, calendarH, 'F');

                doc.setFont('helvetica', 'bold');
                doc.setFontSize(8);
                doc.setTextColor(40, 40, 40);
                for (let d = 0; d < dayLabels.length; d += 1) {
                    const x = calendarX + timeColumnW + (d * dayW);
                    doc.line(x, calendarY, x, calendarY + calendarH);
                    const centerX = x + (dayW / 2);
                    doc.text(dayLabels[d], centerX, calendarY + 5.2, { align: 'center' });
                }

                for (let r = 0; r <= rows; r += 1) {
                    const yLine = calendarY + dayHeaderH + (r * rowH);
                    doc.line(calendarX, yLine, calendarX + calendarW, yLine);
                    if (r % 2 === 0) {
                        const mins = minMinutes + (r * interval);
                        doc.setFont('helvetica', 'normal');
                        doc.setFontSize(7);
                        doc.text(this.formatMinutesLabel(mins), calendarX + 1.5, yLine + 2.5);
                    }
                }

                entries.forEach((entry) => {
                    const dayPos = Math.min(6, Math.max(0, entry.dayIndex - 1));
                    const startOffset = (entry.startMin - minMinutes) / interval;
                    const duration = Math.max(interval, entry.endMin - entry.startMin);
                    const x = calendarX + timeColumnW + (dayPos * dayW) + 0.7;
                    const y = calendarY + dayHeaderH + (startOffset * rowH) + 0.5;
                    const w = dayW - 1.4;
                    let h = Math.max(8, (duration / interval) * rowH - 1);
                    const maxH = (calendarY + calendarH - 0.7) - y;
                    if (maxH <= 1) {
                        return;
                    }
                    h = Math.min(h, maxH);

                    const pname = String(entry.periodname || '');
                    const bg = periodColors[pname] || defaultPeriodColor;
                    doc.setFillColor(bg[0], bg[1], bg[2]);
                    doc.setDrawColor(Math.max(0, bg[0] - 35), Math.max(0, bg[1] - 35), Math.max(0, bg[2] - 35));
                    doc.roundedRect(x, y, w, h, 1, 1, 'FD');

                    doc.setTextColor(255, 255, 255);
                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(7);
                    const status = String(entry.enrollmentstatus || 'Relacionado');
                    const contentLines = [
                        String(entry.subjectname || entry.name || '--'),
                        `${this.formatMinutesLabel(entry.startMin)}-${this.formatMinutesLabel(entry.endMin)} | ${status}`,
                        `Docente: ${entry.instructorname || '--'}`,
                        `Aula: ${entry.classroomname || 'Sin aula'}`,
                    ];
                    let wrapped = [];
                    contentLines.forEach((line) => {
                        const part = doc.splitTextToSize(String(line || ''), w - 1.2);
                        wrapped = wrapped.concat(part);
                    });
                    const maxLines = Math.max(1, Math.floor((h - 1.4) / 2.9));
                    doc.text(wrapped.slice(0, maxLines), x + 0.6, y + 2.7);
                });

                let legendY = calendarY + calendarH + 4;
                let legendX = calendarX;
                doc.setFont('helvetica', 'bold');
                doc.setFontSize(8);
                doc.setTextColor(30, 30, 30);
                doc.text('Periodo:', legendX, legendY);
                legendX += 16;

                Object.keys(periodColors).forEach((label) => {
                    const color = periodColors[label];
                    doc.setFillColor(color[0], color[1], color[2]);
                    doc.roundedRect(legendX, legendY - 3.3, 4, 3.2, 0.5, 0.5, 'F');
                    doc.setFont('helvetica', 'normal');
                    doc.setFontSize(7.5);
                    doc.setTextColor(30, 30, 30);
                    doc.text(label, legendX + 5.5, legendY - 0.4);
                    legendX += (14 + (label.length * 1.8));
                });

                if (withoutSchedule.length > 0) {
                    const sample = withoutSchedule
                        .slice(0, 6)
                        .map((item) => String(item && (item.subjectname || item.name) ? (item.subjectname || item.name) : '--'))
                        .join(' | ');
                    const rest = withoutSchedule.length > 6 ? ` | +${withoutSchedule.length - 6} mas` : '';
                    doc.setFont('helvetica', 'normal');
                    doc.setFontSize(7.5);
                    doc.setTextColor(70, 70, 70);
                    const text = 'Sin horario estructurado: ' + sample + rest;
                    doc.text(doc.splitTextToSize(text, calendarW), calendarX, legendY + 4.8);
                }

                if (entries.length === 0) {
                    doc.setTextColor(120, 30, 30);
                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(10);
                    doc.text('No hay horarios con dia/hora para pintar en el calendario.', calendarX + timeColumnW + 5, calendarY + dayHeaderH + 10);
                }

                const token = this.sanitizeFileToken(student.name || this.studentName || 'estudiante');
                const dateToken = new Date().toISOString().slice(0, 10).replace(/-/g, '');
                const filename = `horario_semanal_${token}_${dateToken}.pdf`;
                doc.save(filename);
            } catch (error) {
                console.error('Error generating student schedule pdf:', error);
                this.showMessage('error', 'Error al generar el PDF del horario.');
            } finally {
                this.exportingSchedulePdf = false;
            }
        },
        async downloadGradesPdf() {
            if (this.exportingGradesPdf) return;
            this.exportingGradesPdf = true;
            try {
                await this.ensurePdfLibrary();
                const jsPDF = (window.jspdf && window.jspdf.jsPDF) ? window.jspdf.jsPDF : window.jsPDF;
                const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
                const pageW = doc.internal.pageSize.getWidth();
                const pageH = doc.internal.pageSize.getHeight();
                const margin = 14;
                const contentW = pageW - margin * 2;

                let logoImage = null;
                let logoRatio = 1;
                const logoUrl = this.getSchedulePdfLogoUrl();
                if (logoUrl) {
                    try {
                        logoImage = await this.loadImageForPdf(logoUrl);
                        if (logoImage && logoImage.naturalWidth > 0) {
                            logoRatio = logoImage.naturalWidth / logoImage.naturalHeight;
                        }
                    } catch (e) { /* skip */ }
                }

                doc.setFillColor(25, 118, 210);
                doc.roundedRect(margin, 10, contentW, 16, 2, 2, 'F');
                doc.setTextColor(255, 255, 255);
                let headerX = margin + 3;
                if (logoImage) {
                    const logoH = 12;
                    const logoW = Math.max(10, Math.min(30, logoH * logoRatio));
                    try {
                        doc.addImage(logoImage, 'PNG', margin + 2, 12, logoW, logoH);
                        headerX = margin + logoW + 5;
                    } catch (e) { /* skip */ }
                }
                doc.setFont('helvetica', 'bold');
                doc.setFontSize(13);
                doc.text('Reporte de Notas', headerX, 18.5);
                doc.setFont('helvetica', 'normal');
                doc.setFontSize(8.5);
                doc.text('Generado: ' + new Date().toLocaleString('es-PA'), headerX, 24);

                doc.setTextColor(0, 0, 0);
                doc.setFont('helvetica', 'bold');
                doc.setFontSize(9);
                doc.text('Estudiante:', margin, 34);
                doc.setFont('helvetica', 'normal');
                doc.text(this.studentName, margin + 22, 34);
                doc.setFont('helvetica', 'bold');
                doc.text('Email:', margin + 105, 34);
                doc.setFont('helvetica', 'normal');
                doc.text(this.studentEmail, margin + 116, 34);

                let y = 40;

                if (this.classId) {
                    // Teacher context: export gradebook (by category)
                    const gb1 = contentW * 0.58;
                    const gb2 = contentW * 0.18;
                    const gb3 = contentW * 0.24;

                    for (const catGroup of this.gradebook) {
                        if (y > pageH - 25) { doc.addPage(); y = margin; }

                        // Category header
                        doc.setFillColor(84, 110, 122);
                        doc.rect(margin, y, contentW, 6, 'F');
                        doc.setTextColor(255, 255, 255);
                        doc.setFont('helvetica', 'bold');
                        doc.setFontSize(8);
                        doc.text(String(catGroup.category || ''), margin + 2, y + 4.2);
                        y += 6;

                        // Column headers
                        doc.setFillColor(207, 226, 255);
                        doc.rect(margin, y, contentW, 5.5, 'F');
                        doc.setTextColor(20, 20, 60);
                        doc.setFontSize(7.5);
                        doc.text('Actividad',    margin + 2,          y + 3.8);
                        doc.text('Ponderación',  margin + gb1 + 2,    y + 3.8);
                        doc.text('Nota',         margin + gb1 + gb2 + 2, y + 3.8);
                        y += 5.5;

                        doc.setFont('helvetica', 'normal');
                        (catGroup.items || []).forEach((item, idx) => {
                            if (y > pageH - 15) { doc.addPage(); y = margin; }
                            if (idx % 2 === 0) {
                                doc.setFillColor(248, 249, 250);
                                doc.rect(margin, y, contentW, 5.5, 'F');
                            }
                            const lines = doc.splitTextToSize(String(item.name || ''), gb1 - 3);
                            const rh = Math.max(5.5, lines.length * 4);
                            doc.setTextColor(30, 30, 30);
                            doc.setFontSize(8);
                            doc.text(lines, margin + 2, y + 4);
                            doc.text(item.weight_pct > 0 ? item.weight_pct.toFixed(1) + '%' : '--',
                                margin + gb1 + 2, y + 4);
                            const gv = parseFloat(item.grade);
                            if (!isNaN(gv)) {
                                doc.setTextColor(gv >= 70 ? 27 : 183, gv >= 70 ? 94 : 28, gv >= 70 ? 32 : 28);
                            } else {
                                doc.setTextColor(100, 100, 100);
                            }
                            doc.text(item.grade !== null ? String(item.grade) : 'Sin calificar',
                                margin + gb1 + gb2 + 2, y + 4);
                            doc.setTextColor(30, 30, 30);
                            doc.setDrawColor(220, 220, 220);
                            doc.line(margin, y + rh, margin + contentW, y + rh);
                            y += rh;
                        });
                        y += 3;
                    }

                    // Final grade row
                    if (y > pageH - 14) { doc.addPage(); y = margin; }
                    doc.setFillColor(21, 101, 192);
                    doc.rect(margin, y, contentW, 8, 'F');
                    doc.setTextColor(255, 255, 255);
                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(9);
                    doc.text('Nota Final:', margin + 2, y + 5.5);
                    const finalGv = this.gradebookFinalGrade;
                    if (finalGv !== null) {
                        doc.setTextColor(finalGv >= 70 ? 144 : 255, finalGv >= 70 ? 238 : 100, finalGv >= 70 ? 144 : 100);
                    }
                    doc.text(finalGv !== null ? finalGv.toFixed(1) : '--', margin + gb1 + gb2 + 2, y + 5.5);
                    y += 10;
                } else {
                    // Academic panel context: export full pensum (all careers/periods/courses)
                    for (const career of this.careersList) {
                        if (y > pageH - 30) { doc.addPage(); y = margin; }
                        doc.setFillColor(25, 118, 210);
                        doc.rect(margin, y, contentW, 7, 'F');
                        doc.setTextColor(255, 255, 255);
                        doc.setFont('helvetica', 'bold');
                        doc.setFontSize(10);
                        doc.text(String(career.career || ''), margin + 2, y + 5);
                        y += 9;

                        const periods = career.periods || {};
                        for (const [periodName, courses] of Object.entries(periods)) {
                            if (y > pageH - 25) { doc.addPage(); y = margin; }
                            doc.setFillColor(232, 240, 254);
                            doc.rect(margin, y, contentW, 6, 'F');
                            doc.setTextColor(25, 60, 130);
                            doc.setFont('helvetica', 'bold');
                            doc.setFontSize(8.5);
                            doc.text(String(periodName || ''), margin + 3, y + 4.2);
                            y += 6;

                            const c1 = contentW * 0.55;
                            const c2 = contentW * 0.25;

                            doc.setFillColor(207, 216, 220);
                            doc.rect(margin, y, contentW, 5.5, 'F');
                            doc.setTextColor(40, 40, 40);
                            doc.setFont('helvetica', 'bold');
                            doc.setFontSize(7.5);
                            doc.text('Asignatura', margin + 2, y + 3.8);
                            doc.text('Estado', margin + c1 + 2, y + 3.8);
                            doc.text('Nota', margin + c1 + c2 + 2, y + 3.8);
                            y += 5.5;

                            doc.setFont('helvetica', 'normal');
                            (courses || []).forEach((course, idx) => {
                                if (y > pageH - 15) { doc.addPage(); y = margin; }
                                if (idx % 2 === 0) {
                                    doc.setFillColor(248, 249, 250);
                                    doc.rect(margin, y, contentW, 5.5, 'F');
                                }
                                const courseDisplayName = String(course.coursename || '') + (course.is_module ? ' (M)' : '');
                                const lines = doc.splitTextToSize(courseDisplayName, c1 - 3);
                                const rh = Math.max(5.5, lines.length * 4);
                                doc.setTextColor(40, 40, 40);
                                doc.setFontSize(8);
                                doc.text(lines, margin + 2, y + 4);
                                doc.text(String(course.statusLabel || ''), margin + c1 + 2, y + 4);
                                const gv = parseFloat(course.grade);
                                if (!isNaN(gv)) {
                                    doc.setTextColor(gv >= 70 ? 27 : 183, gv >= 70 ? 94 : 28, gv >= 70 ? 32 : 28);
                                } else {
                                    doc.setTextColor(100, 100, 100);
                                }
                                doc.text(String(course.grade != null ? course.grade : '--'), margin + c1 + c2 + 2, y + 4);
                                doc.setTextColor(40, 40, 40);
                                doc.setDrawColor(220, 220, 220);
                                doc.line(margin, y + rh, margin + contentW, y + rh);
                                y += rh;
                            });
                            y += 3;
                        }
                        y += 5;
                    }
                }

                const token = this.sanitizeFileToken(this.studentName);
                const dateToken = new Date().toISOString().slice(0, 10).replace(/-/g, '');
                doc.save(`notas_${token}_${dateToken}.pdf`);
            } catch (error) {
                console.error('Error generating grades PDF:', error);
                this.showMessage('error', 'Error al generar el PDF de notas.');
            } finally {
                this.exportingGradesPdf = false;
            }
        },
        async fetchActivitiesForCourse(courseid) {
            if (!courseid || courseid <= 0) return [];
            try {
                const url = window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php');
                const response = await window.axios.get(url, {
                    params: {
                        action: 'local_grupomakro_get_course_activities_for_student',
                        sesskey: M.cfg.sesskey,
                        userId: this.dataStudent.id,
                        courseId: courseid,
                    }
                });
                const payload = response && response.data ? response.data : {};
                if (payload.status === 'success' && payload.data) {
                    const raw = payload.data.activities;
                    return typeof raw === 'string' ? JSON.parse(raw) : (raw || []);
                }
            } catch (e) {
                console.warn('fetchActivitiesForCourse error for courseid=' + courseid, e);
            }
            return [];
        },
        async downloadDetailedGradesPdf() {
            if (this.exportingDetailedGradesPdf) return;
            this.exportingDetailedGradesPdf = true;
            try {
                await this.ensurePdfLibrary();
                const jsPDF = (window.jspdf && window.jspdf.jsPDF) ? window.jspdf.jsPDF : window.jsPDF;
                const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
                const pageW = doc.internal.pageSize.getWidth();
                const pageH = doc.internal.pageSize.getHeight();
                const margin = 14;
                const contentW = pageW - margin * 2;

                // Truncate text to fit in maxWidth, adding ellipsis if needed.
                const truncate = (text, maxWidth) => {
                    const s = String(text || '');
                    if (doc.getTextWidth(s) <= maxWidth) return s;
                    let t = s;
                    while (t.length > 1 && doc.getTextWidth(t + '…') > maxWidth) {
                        t = t.slice(0, -1);
                    }
                    return t + '…';
                };

                let logoImage = null;
                let logoRatio = 1;
                const logoUrl = this.getSchedulePdfLogoUrl();
                if (logoUrl) {
                    try {
                        logoImage = await this.loadImageForPdf(logoUrl);
                        if (logoImage && logoImage.naturalWidth > 0) {
                            logoRatio = logoImage.naturalWidth / logoImage.naturalHeight;
                        }
                    } catch (e) { /* skip */ }
                }

                // ── Header bar ────────────────────────────────────────────────
                doc.setFillColor(69, 39, 160);
                doc.roundedRect(margin, 10, contentW, 16, 2, 2, 'F');
                doc.setTextColor(255, 255, 255);
                let headerX = margin + 3;
                if (logoImage) {
                    const logoH = 12;
                    const logoW = Math.max(10, Math.min(30, logoH * logoRatio));
                    try {
                        doc.addImage(logoImage, 'PNG', margin + 2, 12, logoW, logoH);
                        headerX = margin + logoW + 5;
                    } catch (e) { /* skip */ }
                }
                doc.setFont('helvetica', 'bold');
                doc.setFontSize(13);
                doc.text('Detalle Completo de Notas', headerX, 18.5);
                doc.setFont('helvetica', 'normal');
                doc.setFontSize(8.5);
                doc.text('Generado: ' + new Date().toLocaleString('es-PA'), headerX, 24);

                // ── Student info ──────────────────────────────────────────────
                doc.setTextColor(0, 0, 0);
                doc.setFont('helvetica', 'bold');
                doc.setFontSize(9);
                doc.text('Estudiante:', margin, 34);
                doc.setFont('helvetica', 'normal');
                doc.text(this.studentName, margin + 22, 34);
                doc.setFont('helvetica', 'bold');
                doc.text('Email:', margin + 105, 34);
                doc.setFont('helvetica', 'normal');
                doc.text(this.studentEmail, margin + 116, 34);

                let y = 40;

                // ── Course row column widths ───────────────────────────────────
                // Col 1: course name | Col 2: status | Col 3: grade
                const crC1 = contentW * 0.55;
                const crC2 = contentW * 0.27;
                const crC3 = contentW * 0.18;

                // ── Activity row column widths (inside indented block) ─────────
                const indentL = margin + 4;
                const indentW = contentW - 4;
                // Col 1: activity name | Col 2: ponderación | Col 3: estado | Col 4: nota
                const acC1 = indentW * 0.44;
                const acC2 = indentW * 0.14;
                const acC3 = indentW * 0.22;
                const acC4 = indentW * 0.20;

                const ensureSpace = (needed) => {
                    if (y + needed > pageH - 12) {
                        doc.addPage();
                        y = margin;
                    }
                };

                for (const career of this.careersList) {
                    ensureSpace(20);

                    // ── Career header ─────────────────────────────────────────
                    doc.setFillColor(69, 39, 160);
                    doc.rect(margin, y, contentW, 7, 'F');
                    doc.setTextColor(255, 255, 255);
                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(10);
                    doc.text(String(career.career || ''), margin + 2, y + 5);
                    y += 9;

                    const periods = career.periods || {};
                    for (const [periodName, courses] of Object.entries(periods)) {
                        ensureSpace(16);

                        // ── Period sub-header ─────────────────────────────────
                        doc.setFillColor(237, 231, 246);
                        doc.rect(margin, y, contentW, 6, 'F');
                        doc.setTextColor(69, 39, 160);
                        doc.setFont('helvetica', 'bold');
                        doc.setFontSize(8.5);
                        doc.text(String(periodName || ''), margin + 3, y + 4.2);
                        y += 6;

                        for (const course of (courses || [])) {
                            // Skip courses with no grade unless they are Reprobada.
                            const rawGrade = String(course.grade || '').trim();
                            const hasGrade = rawGrade !== '' && rawGrade !== '-' && rawGrade !== '--';
                            const isReprobada = (String(course.statusLabel || '')).toLowerCase() === 'reprobada';
                            if (!hasGrade && !isReprobada) continue;

                            ensureSpace(14);

                            // Fetch activities for this course
                            const activities = await this.fetchActivitiesForCourse(Number(course.courseid || 0));

                            // ── Course row: Name | Status | Grade ─────────────
                            const courseRowH = 7;
                            doc.setFillColor(232, 240, 254);
                            doc.rect(margin, y, contentW, courseRowH, 'F');

                            // Course name (truncated to one line)
                            doc.setTextColor(20, 30, 80);
                            doc.setFont('helvetica', 'bold');
                            doc.setFontSize(8.5);
                            const detailCourseName = (course.coursename || '') + (course.is_module ? ' (M)' : '');
                            doc.text(truncate(detailCourseName, crC1 - 3), margin + 2, y + 4.8);

                            // Status label
                            doc.setFont('helvetica', 'normal');
                            doc.setFontSize(7.5);
                            doc.setTextColor(70, 70, 100);
                            doc.text(truncate(course.statusLabel || '', crC2 - 2), margin + crC1 + 2, y + 4.8);

                            // Grade (coloured)
                            const cgv = parseFloat(course.grade);
                            if (!isNaN(cgv)) {
                                doc.setTextColor(cgv >= 70 ? 27 : 183, cgv >= 70 ? 94 : 28, cgv >= 70 ? 32 : 28);
                            } else {
                                doc.setTextColor(80, 80, 80);
                            }
                            doc.setFont('helvetica', 'bold');
                            doc.setFontSize(8.5);
                            doc.text(rawGrade || '--', margin + crC1 + crC2 + 2, y + 4.8);
                            y += courseRowH;

                            // ── Activities sub-table ──────────────────────────
                            if (activities.length > 0) {
                                ensureSpace(10);

                                // Activity table header
                                doc.setFillColor(207, 216, 220);
                                doc.rect(indentL, y, indentW, 5, 'F');
                                doc.setTextColor(40, 40, 40);
                                doc.setFont('helvetica', 'bold');
                                doc.setFontSize(7);
                                doc.text('Actividad',    indentL + 2,                     y + 3.5);
                                doc.text('Ponderación',  indentL + acC1 + 2,              y + 3.5);
                                doc.text('Estado',       indentL + acC1 + acC2 + 2,       y + 3.5);
                                doc.text('Nota',         indentL + acC1 + acC2 + acC3 + 2, y + 3.5);
                                y += 5;

                                const actRowH = 5;
                                doc.setFont('helvetica', 'normal');
                                activities.forEach((act, idx) => {
                                    ensureSpace(actRowH + 2);
                                    if (idx % 2 === 0) {
                                        doc.setFillColor(250, 250, 252);
                                        doc.rect(indentL, y, indentW, actRowH, 'F');
                                    }
                                    doc.setTextColor(40, 40, 40);
                                    doc.setFontSize(7.5);

                                    // Activity name — single line, truncated
                                    doc.text(truncate(act.name || '', acC1 - 3), indentL + 2, y + 3.5);

                                    // Ponderación
                                    const wStr = (act.weight != null && act.weight !== undefined)
                                        ? (Number(act.weight).toFixed(2) + '%')
                                        : '--';
                                    doc.text(wStr, indentL + acC1 + 2, y + 3.5);

                                    // Estado
                                    doc.text(act.completed ? 'Completado' : 'Pendiente',
                                        indentL + acC1 + acC2 + 2, y + 3.5);

                                    // Nota (coloured)
                                    const agv = parseFloat(act.grade);
                                    if (!isNaN(agv)) {
                                        doc.setTextColor(agv >= 70 ? 27 : 183, agv >= 70 ? 94 : 28, agv >= 70 ? 32 : 28);
                                    } else {
                                        doc.setTextColor(100, 100, 100);
                                    }
                                    doc.text(String(act.grade || 'Sin calificar'),
                                        indentL + acC1 + acC2 + acC3 + 2, y + 3.5);

                                    doc.setTextColor(40, 40, 40);
                                    doc.setDrawColor(230, 230, 230);
                                    doc.line(indentL, y + actRowH, indentL + indentW, y + actRowH);
                                    y += actRowH;
                                });
                            }

                            y += 2;
                        }
                        y += 4;
                    }
                    y += 5;
                }

                const token = this.sanitizeFileToken(this.studentName);
                const dateToken = new Date().toISOString().slice(0, 10).replace(/-/g, '');
                doc.save(`notas_detalle_${token}_${dateToken}.pdf`);
            } catch (error) {
                console.error('Error generating detailed grades PDF:', error);
                this.showMessage('error', 'Error al generar el PDF de detalle de notas.');
            } finally {
                this.exportingDetailedGradesPdf = false;
            }
        },
        async getpensum() {
            const careersList = this.careersList;
            this.loadingPensum = true;
            try {
                for (const element of careersList) {
                    this.$set(element, 'periods', null);
                    const data = await this.getcarrers(element.planid, 0);
                    const groupedByPeriodName = {};

                    if (data && typeof data === 'object') {
                        Object.values(data).forEach(periodInfo => {
                            if (periodInfo && periodInfo.periodName) {
                                const courses = periodInfo.courses || [];
                                // Rebuild the homologation cache from the canonical backend payload
                                // so the chip stays consistent with gmk_course_progre.
                                courses.forEach((course) => {
                                    const cid = Number(course && course.courseid ? course.courseid : 0);
                                    if (cid > 0 && course.homologation_type) {
                                        this.$set(this.homologations, cid, {
                                            homologation_type: course.homologation_type,
                                            homologation_note: course.homologation_note || '',
                                            homologation_at:   Number(course.homologation_at || 0),
                                            homologation_by:   Number(course.homologation_by || 0)
                                        });
                                    }
                                });
                                groupedByPeriodName[periodInfo.periodName] = courses;
                            }
                        });
                    }
                    this.$set(element, 'periods', groupedByPeriodName);
                }
            } finally {
                this.loadingPensum = false;
            }
        },
        async getcarrers(id, attempt = 0) {
            try {
                const url = window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php');

                const params = {
                    action: 'local_grupomakro_get_student_learning_plan_pensum',
                    sesskey: M.cfg.sesskey,
                    userId: this.dataStudent.id,
                    learningPlanId: id
                };

                const response = await window.axios.get(url, { params });

                if (!response.data || response.data.status !== 'success' || !response.data.data) {
                    return {};
                }

                const result = response.data.data;
                const pensumStr = result.pensum;

                const data = typeof pensumStr === 'string'
                    ? JSON.parse(pensumStr)
                    : pensumStr;

                return data || {};
            } catch (error) {
                const statusCode = error && error.response ? Number(error.response.status || 0) : 0;
                if (statusCode === 503 && attempt < 1) {
                    await new Promise(resolve => setTimeout(resolve, 900));
                    return this.getcarrers(id, attempt + 1);
                }
                console.error('Error fetching pensum:', error);
                return {};
            }
        },
        hasActiveClasses(course) {
            return Number(course && course.activeclasscount ? course.activeclasscount : 0) > 0;
        },
        hasAllowedStatusForEnroll(course) {
            const statusLabel = String((course && course.statusLabel) ? course.statusLabel : '').trim().toLowerCase();
            return statusLabel === 'disponible' || statusLabel === 'no disponible' || statusLabel === 'reprobada';
        },
        canEnrollInCourse(course) {
            // Disable enrollment if student already has an independent module for this course.
            const key = this.getCourseKey(course);
            if (this.moduleStatusMap && this.moduleStatusMap[key] && this.moduleStatusMap[key].enrolled) {
                return false;
            }
            return this.hasActiveClasses(course) && this.hasAllowedStatusForEnroll(course);
        },
        canEnrollInModule(course) {
            const statusLabel = String((course && course.statusLabel) ? course.statusLabel : '').trim().toLowerCase();
            return statusLabel !== 'cursando' && statusLabel !== 'aprobada';
        },
        async openEnrollDialog(course) {
            if (!this.canEnrollInCourse(course)) {
                return;
            }

            this.selectedCourse = course;
            this.enrollDialog = true;
            this.loadingEnrollClasses = true;
            this.enrollClasses = [];
            this.enrollClassesError = '';

            try {
                const url = window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php');
                const params = {
                    action: 'local_grupomakro_get_active_classes_for_course',
                    sesskey: M.cfg.sesskey,
                    userId: this.dataStudent.id,
                    coreCourseId: Number(course.courseid || 0),
                    learningCourseId: Number(course.learningcourseid || 0),
                    learningPlanId: Number(course.learningplanid || 0),
                };

                const response = await window.axios.get(url, { params });
                const payload = response && response.data ? response.data : {};

                if (payload.status === 'success' && Array.isArray(payload.classes)) {
                    this.enrollClasses = payload.classes;
                } else {
                    this.enrollClassesError = payload.message || 'No se pudieron cargar los cursos activos.';
                }
            } catch (error) {
                console.error('Error loading active classes for enrollment:', error);
                this.enrollClassesError = 'Error consultando cursos activos.';
            } finally {
                this.loadingEnrollClasses = false;
            }
        },
        closeEnrollDialog() {
            this.enrollDialog = false;
            this.selectedCourse = null;
            this.enrollClasses = [];
            this.enrollClassesError = '';
            this.enrollingClassId = null;
        },
        async enrollStudentInClass(item) {
            if (!item || !item.id || this.enrollingClassId) {
                return;
            }

            this.enrollingClassId = item.id;
            try {
                const url = window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php');
                const params = {
                    action: 'local_grupomakro_manual_enroll',
                    sesskey: M.cfg.sesskey,
                    classId: Number(item.id),
                    userId: Number(this.dataStudent.id),
                    learningPlanId: Number(this.selectedCourse && this.selectedCourse.learningplanid ? this.selectedCourse.learningplanid : 0),
                };

                const response = await window.axios.get(url, { params });
                const payload = response && response.data ? response.data : {};
                const result = (payload.status === 'success' && payload.data) ? payload.data : payload;

                if (result.status === 'ok' || result.status === 'warning') {
                    item.alreadyenrolled = true;
                    item.enrolled = Number(item.enrolled || 0) + (result.status === 'ok' ? 1 : 0);
                    this.showMessage(result.status === 'ok' ? 'success' : 'warning', result.message || 'Operacion finalizada.');
                    // Refresh pensum immediately so status labels reflect "Cursando" without reopening the modal.
                    await this.getpensum();
                } else {
                    this.showMessage('error', result.message || 'No se pudo inscribir al estudiante.');
                }
            } catch (error) {
                console.error('Error enrolling student in class:', error);
                this.showMessage('error', 'Error inscribiendo al estudiante.');
            } finally {
                this.enrollingClassId = null;
            }
        },
        showMessage(type, message) {
            if (window.Swal) {
                window.Swal.fire({
                    icon: type,
                    text: message,
                    confirmButtonText: 'Aceptar'
                });
                return;
            }
            window.alert(message);
        },
        getClassDaysLabel(days) {
            if (!days) {
                return '--';
            }
            const map = ['Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab', 'Dom'];
            const pieces = String(days).split('/');
            const labels = [];
            pieces.forEach((flag, idx) => {
                if (String(flag) === '1' && map[idx]) {
                    labels.push(map[idx]);
                }
            });
            return labels.length ? labels.join(', ') : '--';
        },
        isOverCapacity(item) {
            const cap = Number(item && item.classroomcapacity ? item.classroomcapacity : 0);
            if (cap <= 0) {
                return false;
            }
            return Number(item.enrolled || 0) > cap;
        },
        canWithdrawFromCourse(course) {
            const label = String((course && course.statusLabel) ? course.statusLabel : '').trim().toLowerCase();
            if (label !== 'cursando') return false;
            if (Number(course && course.progressclassid ? course.progressclassid : 0) > 0) return true;
            if (Number(course && course.module_classid ? course.module_classid : 0) > 0) return true;
            return false;
        },
        async enrollInModule(course) {
            const key = this.getCourseKey(course);
            const existing = this.moduleStatusMap[key];
            if (existing) {
                const date = existing.duedate
                    ? new Date(existing.duedate * 1000).toLocaleDateString('es-PA', { day: '2-digit', month: 'short', year: 'numeric' })
                    : '—';
                const period = existing.periodname ? ' | Período: ' + existing.periodname : '';
                this.showMessage('info', 'Ya inscrito en módulo' + period + '. Plazo: ' + date);
                return;
            }

            // Pre-fetch the active lective period name to show in the confirmation dialog
            let periodLabel = '';
            try {
                const url = window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php');
                const preRes = await window.axios.get(url, { params: {
                    action: 'local_grupomakro_get_student_period',
                    sesskey: M.cfg.sesskey,
                    userId: this.dataStudent.id,
                }});
                const preData = ((preRes.data || {}).data) || {};
                if (preData.periodname) {
                    periodLabel = '<br><small><b>Periodo activo:</b> ' + preData.periodname + '</small>';
                }
            } catch (_) {}

            const swResult = await window.Swal.fire({
                title: 'Inscribir en Módulo',
                html: '¿Inscribir a <b>' + this.studentName + '</b> en el módulo de <b>' + (course.coursename || '') + '</b>?'
                    + periodLabel
                    + '<br><small class="grey--text">El estudiante tendrá <b>25 días</b> para completar las actividades.</small>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Inscribir',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#00796B',
            });
            if (!swResult.isConfirmed) return;

            this.enrollingModuleKey = key;
            try {
                const url = window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php');
                const response = await window.axios.get(url, {
                    params: {
                        action: 'local_grupomakro_enroll_module',
                        sesskey: M.cfg.sesskey,
                        userId: this.dataStudent.id,
                        coreCourseId: Number(course.courseid || 0),
                        learningPlanId: Number(course.learningplanid || 0),
                    }
                });
                const payload = response.data || {};
                console.log('[enroll_module] raw response:', JSON.stringify(payload));
                const data = (payload.data) ? payload.data : payload;

                if (data.status === 'ok') {
                    this.$set(this.moduleStatusMap, key, { enrolled: true, duedate: data.duedate || 0, periodname: data.periodname || '' });
                    this.showMessage('success', data.message || 'Inscrito en módulo correctamente.');
                } else if (data.status === 'warning') {
                    this.$set(this.moduleStatusMap, key, { enrolled: true, duedate: data.duedate || 0, periodname: data.periodname || '' });
                    this.showMessage('warning', data.message || 'Ya estaba inscrito en este módulo.');
                } else {
                    this.showMessage('error', data.message || 'No se pudo inscribir en el módulo.');
                }
            } catch (e) {
                console.error('Error enrolling in module:', e);
                this.showMessage('error', 'Error al inscribir en módulo.');
            } finally {
                this.enrollingModuleKey = null;
            }
        },
        getCourseKey(course) {
            return String(course && course.progressclassid ? course.progressclassid : 0) + '_' + String(course && course.courseid ? course.courseid : 0);
        },
        async withdrawFromCourse(course) {
            const regularClassId = Number(course && course.progressclassid ? course.progressclassid : 0);
            const moduleClassId  = Number(course && course.module_classid  ? course.module_classid  : 0);
            const classId = regularClassId || moduleClassId;
            const isModuleWithdrawal = !regularClassId && moduleClassId > 0;
            if (!classId || this.withdrawingCourseKey) return;

            const courseName = course.coursename || 'esta asignatura';
            const studentName = this.studentName;

            const confirmed = await (async () => {
                if (window.Swal) {
                    const bodyText = isModuleWithdrawal
                        ? `<b>${studentName}</b> será <b>retirado del módulo</b> de <b>${courseName}</b>.<br><br>` +
                          `Se eliminará la inscripción del módulo y el estado volverá a <em>Disponible</em>.`
                        : `<b>${studentName}</b> será <b>retirado</b> de <b>${courseName}</b>.<br><br>` +
                          `Se eliminará su inscripción en el grupo, se des-matriculará del curso en Moodle ` +
                          `y su estado volverá a <em>Disponible</em> para poder inscribirse nuevamente.`;
                    const result = await window.Swal.fire({
                        icon: 'warning',
                        title: isModuleWithdrawal ? '¿Retirar del módulo?' : '¿Retirar estudiante?',
                        html: bodyText,
                        showCancelButton: true,
                        confirmButtonColor: '#d33',
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: 'Sí, retirar',
                        cancelButtonText: 'Cancelar',
                    });
                    return result.isConfirmed;
                }
                return window.confirm(
                    `¿Retirar a ${studentName} de ${courseName}?\n\n` +
                    `Se eliminará su inscripción. El estado volverá a Disponible para re-inscripción.`
                );
            })();

            if (!confirmed) return;

            this.withdrawingCourseKey = this.getCourseKey(course);
            try {
                const url = window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php');
                const params = {
                    action:  'local_grupomakro_withdraw_student',
                    sesskey: M.cfg.sesskey,
                    classId: classId,
                    userId:  Number(this.dataStudent.id),
                    learningPlanId: Number(course && course.learningplanid ? course.learningplanid : 0),
                };
                const response = await window.axios.get(url, { params });
                const payload  = response && response.data ? response.data : {};
                const result   = (payload.status === 'success' && payload.data) ? payload.data : payload;

                if (result.status === 'ok') {
                    this.showMessage('success', result.message || 'Estudiante retirado correctamente.');
                    if (isModuleWithdrawal) {
                        this.$set(this.moduleStatusMap, this.getCourseKey(course), null);
                    }
                    // Reload pensum to reflect new status.
                    await this.getpensum();
                } else {
                    this.showMessage('error', result.message || 'No se pudo retirar al estudiante.');
                }
            } catch (error) {
                console.error('Error withdrawing student:', error);
                this.showMessage('error', 'Error al retirar al estudiante.');
            } finally {
                this.withdrawingCourseKey = null;
            }
        }
    },
    computed: {
        lang() { return window.strings || {}; },
        token() { return window.userToken; },
        siteUrl() { return window.location.origin + '/local/grupomakro_core/ajax.php'; },
        careersList() {
            const list = this.dataStudent && (this.dataStudent.carrers || this.dataStudent.careers)
                ? (this.dataStudent.carrers || this.dataStudent.careers)
                : [];
            return Array.isArray(list) ? list : [];
        },
        creditPlanOptions() {
            const opts = [{ text: 'Todos los planes', value: 0 }];
            const seen = {};
            this.careersList.forEach((c) => {
                const pid = c && c.planid ? Number(c.planid) : 0;
                if (pid > 0 && !seen[pid]) {
                    seen[pid] = true;
                    opts.push({ text: c.career || ('Plan ' + pid), value: pid });
                }
            });
            return opts;
        },
        studentName() {
            return (this.dataStudent && this.dataStudent.name) ? this.dataStudent.name : '--';
        },
        studentEmail() {
            return (this.dataStudent && this.dataStudent.email) ? this.dataStudent.email : '--';
        },
        showSchedulePdfButton() {
            return !this.classId;
        },
        canExportGradesPdf() {
            if (this.classId) {
                return !this.loadingGradebook && this.gradebook.length > 0;
            }
            return !this.loadingPensum && this.careersList.length > 0;
        },
        canExportDetailedPdf() {
            return !this.classId && !this.loadingPensum && this.careersList.length > 0;
        },
        gradebookWeightedTotal() {
            const items = this.gradebook.flatMap(g => g.items || []);
            const gradeable = items.filter(i => i.weight_pct > 0);
            if (!gradeable.length) return null;
            let sum = 0;
            gradeable.forEach(item => {
                const grade = (item.grade !== null && item.grade !== undefined) ? parseFloat(item.grade) : 0;
                const max   = (item.grade_max > 0) ? item.grade_max : 100;
                sum += (grade / max) * item.weight_pct;
            });
            return Math.round(sum * 10) / 10;
        },
        // Nota final que se muestra: usa el cálculo ponderado con asistencia log-based
        // (igual metodología que gmk_batch_weighted_grades en el backend).
        // gradebookCourseGrade (Moodle course total) se evita porque puede ser incorrecto
        // cuando grade_regrade_final_grades() infla la nota de asistencia de ausentes.
        gradebookFinalGrade() {
            return this.gradebookWeightedTotal;
        },
        selectedCourseName() {
            return this.selectedCourse && this.selectedCourse.coursename ? this.selectedCourse.coursename : '--';
        },
        enrollableClasses() {
            return Array.isArray(this.enrollClasses) ? this.enrollClasses : [];
        },
        homologationGradeError() {
            const raw = this.homologationForm && this.homologationForm.grade;
            if (raw === null || raw === undefined || raw === '') return 'La nota es obligatoria.';
            const v = Number(raw);
            if (isNaN(v)) return 'La nota debe ser numérica.';
            if (v < 0 || v > 100) return 'La nota debe estar entre 0 y 100.';
            return '';
        },
        homologationObservationError() {
            const obs = (this.homologationForm && this.homologationForm.observation || '').trim();
            if (obs.length === 0) return 'La observación es obligatoria.';
            if (obs.length < 5) return 'La observación debe tener al menos 5 caracteres.';
            return '';
        },
        isHomologationFormValid() {
            return !this.homologationGradeError && !this.homologationObservationError
                && ['suficiencia', 'migracion', 'homologacion'].includes(this.homologationForm.type);
        }
    },
});
