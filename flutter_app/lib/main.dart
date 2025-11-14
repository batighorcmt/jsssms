import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

void main() => runApp(const JssApp());

class JssApp extends StatelessWidget {
  const JssApp({super.key});
  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: 'Batighor JSS Management',
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: Colors.indigo),
        useMaterial3: true,
        fontFamily: 'Segoe UI',
      ),
      home: const SplashGate(),
    );
  }
}

/// Splash gate: shows Splash.gif then routes to Login or Dashboard
class SplashGate extends StatefulWidget {
  const SplashGate({super.key});
  @override
  State<SplashGate> createState() => _SplashGateState();
}

class _SplashGateState extends State<SplashGate> {
  bool _ready = false;
  String? _token;
  @override
  void initState() {
    super.initState();
    _init();
  }

  Future<void> _init() async {
    final prefs = await SharedPreferences.getInstance();
    _token = prefs.getString('token');
    await Future.delayed(const Duration(milliseconds: 900));
    if (!mounted) return;
    setState(() => _ready = true);
  }

  @override
  Widget build(BuildContext context) {
    if (!_ready) {
      return Scaffold(
        backgroundColor: Colors.white,
        body: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              SizedBox(
                  height: 160,
                  width: 160,
                  child: Image.asset('assets/images/Splash.gif',
                      fit: BoxFit.contain)),
              const SizedBox(height: 20),
              const Text('Batighor JSS Management',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.w600)),
            ],
          ),
        ),
      );
    }
    return _token == null
        ? const LoginScreen()
        : DashboardScreen(token: _token!);
  }
}

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _userCtrl = TextEditingController();
  final _passCtrl = TextEditingController();
  bool _busy = false;
  String? _error;
  static const baseUrl =
      'https://jss.batighorbd.com/api'; // TODO: replace for production

  Future<void> _login() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final url = Uri.parse('$baseUrl/auth/login.php');
      final resp = await http.post(url,
          headers: {'Content-Type': 'application/json'},
          body: jsonEncode(
              {'username': _userCtrl.text.trim(), 'password': _passCtrl.text}));
      final data = jsonDecode(resp.body);
      if (resp.statusCode == 200 && data['success'] == true) {
        final prefs = await SharedPreferences.getInstance();
        await prefs.setString('token', data['data']['token']);
        if (!mounted) return;
        Navigator.of(context).pushReplacement(MaterialPageRoute(
            builder: (_) => DashboardScreen(token: data['data']['token'])));
      } else {
        setState(() {
          _error = data['error']?.toString() ?? 'Login failed';
        });
      }
    } catch (e) {
      setState(() {
        _error = e.toString();
      });
    } finally {
      setState(() {
        _busy = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
              colors: [Color(0xFF4F46E5), Color(0xFF6366F1), Color(0xFF818CF8)],
              begin: Alignment.topLeft,
              end: Alignment.bottomRight),
        ),
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 370),
            child: Card(
              elevation: 10,
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(26)),
              child: Padding(
                padding: const EdgeInsets.all(28),
                child: Form(
                  key: _formKey,
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    SizedBox(
                        height: 90,
                        width: 90,
                        child: Image.asset('assets/images/icon.png',
                            fit: BoxFit.contain)),
                    const SizedBox(height: 14),
                    Text('Batighor JSS Management',
                        textAlign: TextAlign.center,
                        style: Theme.of(context)
                            .textTheme
                            .titleMedium
                            ?.copyWith(fontWeight: FontWeight.bold)),
                    const SizedBox(height: 24),
                    TextFormField(
                      controller: _userCtrl,
                      decoration: const InputDecoration(
                          labelText: 'Username',
                          prefixIcon: Icon(Icons.person_outline)),
                      validator: (v) =>
                          v == null || v.isEmpty ? 'Required' : null,
                    ),
                    const SizedBox(height: 12),
                    TextFormField(
                      controller: _passCtrl,
                      decoration: const InputDecoration(
                          labelText: 'Password',
                          prefixIcon: Icon(Icons.lock_outline)),
                      obscureText: true,
                      validator: (v) =>
                          v == null || v.isEmpty ? 'Required' : null,
                    ),
                    const SizedBox(height: 20),
                    if (_error != null)
                      Text(_error!, style: const TextStyle(color: Colors.red)),
                    const SizedBox(height: 10),
                    SizedBox(
                        width: double.infinity,
                        child: ElevatedButton(
                          onPressed: _busy ? null : _login,
                          style: ElevatedButton.styleFrom(
                              padding: const EdgeInsets.symmetric(vertical: 14),
                              shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(14))),
                          child: _busy
                              ? SizedBox(
                                  height: 26,
                                  width: 26,
                                  child:
                                      Image.asset('assets/images/loading.gif'))
                              : const Text('Login'),
                        )),
                  ]),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class DashboardScreen extends StatefulWidget {
  final String token;
  const DashboardScreen({super.key, required this.token});
  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  List<dynamic> duties = [];
  bool loading = true;
  String? error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => loading = true);
    try {
      duties = await ApiService.getDuties();
    } catch (e) {
      error = e.toString();
    }
    setState(() => loading = false);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Dashboard')),
      body: Padding(
        padding: const EdgeInsets.all(8.0),
        child: GridView.count(
          crossAxisCount: 2,
          crossAxisSpacing: 10,
          mainAxisSpacing: 10,
          children: <Widget>[
            _buildDashboardCard(
                context, 'Today\'s Duties', Icons.event_note, Colors.blue, () {
              Navigator.push(context,
                  MaterialPageRoute(builder: (context) => DutiesScreen()));
            }),
            _buildDashboardCard(
                context, 'Exam Seat Plan', Icons.event_seat, Colors.green, () {
              Navigator.push(context,
                  MaterialPageRoute(builder: (context) => SeatPlanScreen()));
            }),
            _buildDashboardCard(
                context, 'Marks Entry', Icons.edit, Colors.orange, () {
              Navigator.push(context,
                  MaterialPageRoute(builder: (context) => MarksEntryScreen()));
            }),
          ],
        ),
      ),
      floatingActionButton: FloatingActionButton(
        onPressed: _load,
        child: const Icon(Icons.refresh),
      ),
    );
  }

  Card _buildDashboardCard(BuildContext context, String title, IconData icon,
      Color color, VoidCallback onTap) {
    return Card(
      elevation: 4,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(12),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              CircleAvatar(
                radius: 28,
                backgroundColor: color,
                child: Icon(icon, size: 32, color: Colors.white),
              ),
              const SizedBox(height: 12),
              Text(
                title,
                textAlign: TextAlign.center,
                style:
                    const TextStyle(fontSize: 16, fontWeight: FontWeight.w500),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class DutiesScreen extends StatefulWidget {
  const DutiesScreen({super.key});
  @override
  State<DutiesScreen> createState() => _DutiesScreenState();
}

class _DutiesScreenState extends State<DutiesScreen> {
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Today\'s Duties')),
      body: Center(
        child: Text('Duties Screen - Coming Soon!'),
      ),
    );
  }
}

class MarksEntryScreen extends StatefulWidget {
  @override
  _MarksEntryScreenState createState() => _MarksEntryScreenState();
}

class _MarksEntryScreenState extends State<MarksEntryScreen> {
  List<dynamic> _exams = [];
  List<dynamic> _classes = [];
  List<dynamic> _sections = [];
  String? _selectedExam;
  String? _selectedClass;
  String? _selectedSection;
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _fetchDropdownData();
  }

  Future<void> _fetchDropdownData() async {
    setState(() {
      _isLoading = true;
    });
    try {
      final exams = await ApiService.getExams();
      final classes = await ApiService.getClasses();
      final sections = await ApiService.getSections();
      setState(() {
        _exams = exams;
        _classes = classes;
        _sections = sections;
        _isLoading = false;
      });
    } catch (e) {
      setState(() {
        _isLoading = false;
      });
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Failed to load data: $e')));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text('Marks Entry - Select Criteria'),
      ),
      body: _isLoading
          ? Center(
              child: Image.asset('assets/images/loading.gif',
                  width: 50, height: 50))
          : Padding(
              padding: const EdgeInsets.all(16.0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _buildDropdown(
                    hint: 'Select Exam',
                    value: _selectedExam,
                    items: _exams.map((exam) {
                      return DropdownMenuItem(
                        value: exam['id'].toString(),
                        child: Text(exam['name']),
                      );
                    }).toList(),
                    onChanged: (value) {
                      setState(() {
                        _selectedExam = value;
                      });
                    },
                  ),
                  SizedBox(height: 16),
                  _buildDropdown(
                    hint: 'Select Class',
                    value: _selectedClass,
                    items: _classes.map((c) {
                      return DropdownMenuItem(
                        value: c['class_id'].toString(),
                        child: Text(c['class_name']),
                      );
                    }).toList(),
                    onChanged: (value) {
                      setState(() {
                        _selectedClass = value;
                      });
                    },
                  ),
                  SizedBox(height: 16),
                  _buildDropdown(
                    hint: 'Select Section',
                    value: _selectedSection,
                    items: _sections.map((s) {
                      return DropdownMenuItem(
                        value: s['section_id'].toString(),
                        child: Text(s['section_name']),
                      );
                    }).toList(),
                    onChanged: (value) {
                      setState(() {
                        _selectedSection = value;
                      });
                    },
                  ),
                  SizedBox(height: 32),
                  ElevatedButton(
                    onPressed: (_selectedExam != null &&
                            _selectedClass != null &&
                            _selectedSection != null)
                        ? () {
                            Navigator.push(
                              context,
                              MaterialPageRoute(
                                builder: (context) => SubjectsScreen(
                                  examId: _selectedExam!,
                                  classId: _selectedClass!,
                                  sectionId: _selectedSection!,
                                ),
                              ),
                            );
                          }
                        : null,
                    child: Text('Fetch Subjects'),
                    style: ElevatedButton.styleFrom(
                      padding: EdgeInsets.symmetric(vertical: 16),
                    ),
                  ),
                ],
              ),
            ),
    );
  }

  Widget _buildDropdown({
    required String hint,
    required String? value,
    required List<DropdownMenuItem<String>> items,
    required ValueChanged<String?> onChanged,
  }) {
    return DropdownButtonFormField<String>(
      decoration: InputDecoration(
        labelText: hint,
        border: OutlineInputBorder(),
      ),
      value: value,
      items: items,
      onChanged: onChanged,
    );
  }
}

class SubjectsScreen extends StatefulWidget {
  final String examId;
  final String classId;
  final String sectionId;

  SubjectsScreen(
      {required this.examId, required this.classId, required this.sectionId});

  @override
  _SubjectsScreenState createState() => _SubjectsScreenState();
}

class _SubjectsScreenState extends State<SubjectsScreen> {
  late Future<List<dynamic>> _subjectsFuture;

  @override
  void initState() {
    super.initState();
    _subjectsFuture =
        ApiService.getSubjectsForTeacher(widget.examId, widget.classId);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text('Select Subject'),
      ),
      body: FutureBuilder<List<dynamic>>(
        future: _subjectsFuture,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return Center(
                child: Image.asset('assets/images/loading.gif',
                    width: 50, height: 50));
          } else if (snapshot.hasError) {
            return Center(child: Text('Error: ${snapshot.error}'));
          } else if (!snapshot.hasData || snapshot.data!.isEmpty) {
            return Center(
                child: Text('No subjects found for the selected criteria.'));
          } else {
            final subjects = snapshot.data!;
            return ListView.builder(
              itemCount: subjects.length,
              itemBuilder: (context, index) {
                final subject = subjects[index];
                return Card(
                  margin:
                      const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
                  child: ListTile(
                    title: Text(subject['subject_name']),
                    subtitle: Text('Code: ${subject['subject_code']}'),
                    trailing: Icon(Icons.arrow_forward_ios),
                    onTap: () {
                      Navigator.push(
                        context,
                        MaterialPageRoute(
                          builder: (context) => StudentListMarksScreen(
                            examId: widget.examId,
                            classId: widget.classId,
                            sectionId: widget.sectionId,
                            subjectId: subject['subject_id'].toString(),
                          ),
                        ),
                      );
                    },
                  ),
                );
              },
            );
          }
        },
      ),
    );
  }
}

class StudentListMarksScreen extends StatefulWidget {
  final String examId;
  final String classId;
  final String sectionId;
  final String subjectId;

  StudentListMarksScreen(
      {required this.examId,
      required this.classId,
      required this.sectionId,
      required this.subjectId});

  @override
  _StudentListMarksScreenState createState() => _StudentListMarksScreenState();
}

class _StudentListMarksScreenState extends State<StudentListMarksScreen> {
  late Future<List<dynamic>> _studentsFuture;
  final Map<String, TextEditingController> _marksControllers = {};
  bool _isSaving = false;

  @override
  void initState() {
    super.initState();
    _studentsFuture = ApiService.getStudentsForMarking(
        widget.examId, widget.classId, widget.sectionId, widget.subjectId);
  }

  @override
  void dispose() {
    _marksControllers.values.forEach((controller) => controller.dispose());
    super.dispose();
  }

  Future<void> _submitMarks() async {
    setState(() {
      _isSaving = true;
    });

    final marks = _marksControllers.entries.map((entry) {
      return {'student_id': entry.key, 'marks': entry.value.text};
    }).toList();

    try {
      await ApiService.submitMarks(
          widget.examId, widget.classId, widget.subjectId, marks);
      ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Marks submitted successfully!')));
      Navigator.pop(context);
    } catch (e) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Failed to submit marks: $e')));
    } finally {
      setState(() {
        _isSaving = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text('Enter Marks'),
        actions: [
          IconButton(
            icon: Icon(Icons.save),
            onPressed: _isSaving ? null : _submitMarks,
          ),
        ],
      ),
      body: FutureBuilder<List<dynamic>>(
        future: _studentsFuture,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return Center(
                child: Image.asset('assets/images/loading.gif',
                    width: 50, height: 50));
          } else if (snapshot.hasError) {
            return Center(child: Text('Error: ${snapshot.error}'));
          } else if (!snapshot.hasData || snapshot.data!.isEmpty) {
            return Center(child: Text('No students found.'));
          } else {
            final students = snapshot.data!;
            return Column(
              children: [
                Expanded(
                  child: ListView.builder(
                    itemCount: students.length,
                    itemBuilder: (context, index) {
                      final student = students[index];
                      final studentId = student['student_id'].toString();
                      if (!_marksControllers.containsKey(studentId)) {
                        _marksControllers[studentId] = TextEditingController(
                            text: student['marks_obtained']?.toString() ?? '');
                      }
                      return Card(
                        margin: const EdgeInsets.symmetric(
                            horizontal: 12, vertical: 4),
                        child: ListTile(
                          title: Text(student['name']),
                          subtitle: Text('Roll: ${student['roll_no']}'),
                          trailing: SizedBox(
                            width: 80,
                            child: TextField(
                              controller: _marksControllers[studentId],
                              keyboardType: TextInputType.number,
                              textAlign: TextAlign.center,
                              decoration: InputDecoration(
                                border: OutlineInputBorder(),
                                contentPadding:
                                    EdgeInsets.symmetric(horizontal: 8),
                              ),
                            ),
                          ),
                        ),
                      );
                    },
                  ),
                ),
                if (_isSaving)
                  Padding(
                    padding: const EdgeInsets.all(16.0),
                    child: Image.asset('assets/images/loading.gif',
                        width: 50, height: 50),
                  ),
              ],
            );
          }
        },
      ),
    );
  }
}

class SeatPlanScreen extends StatefulWidget {
  const SeatPlanScreen({super.key});
  @override
  State<SeatPlanScreen> createState() => _SeatPlanScreenState();
}

class _SeatPlanScreenState extends State<SeatPlanScreen> {
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Exam Seat Plan')),
      body: Center(
        child: Text('Seat Plan Screen - Coming Soon!'),
      ),
    );
  }
}

class AttendanceScreen extends StatefulWidget {
  final String token;
  final int planId;
  final int roomId;
  final String date;
  const AttendanceScreen(
      {super.key,
      required this.token,
      required this.planId,
      required this.roomId,
      required this.date});
  @override
  State<AttendanceScreen> createState() => _AttendanceScreenState();
}

class _AttendanceScreenState extends State<AttendanceScreen> {
  bool loading = true;
  String? error;
  List<dynamic> students = [];
  bool saving = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => loading = true);
    try {
      students = await ApiService.getAttendance(
          widget.date, widget.planId, widget.roomId);
    } catch (e) {
      error = e.toString();
    }
    setState(() => loading = false);
  }

  Future<void> _submit() async {
    setState(() => saving = true);
    try {
      final entries = students
          .map((s) => {'student_id': s['student_id'], 'status': s['status']})
          .toList();
      await ApiService.submitAttendance(
          widget.date, widget.planId, widget.roomId, entries);
      ScaffoldMessenger.of(context)
          .showSnackBar(const SnackBar(content: Text('Attendance saved')));
    } catch (e) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(e.toString())));
    } finally {
      setState(() => saving = false);
    }
  }

  void _toggleStatus(int idx) {
    setState(() {
      final cur = students[idx]['status'];
      students[idx]['status'] = cur == 'present' ? 'absent' : 'present';
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text('Attendance ${widget.date}')),
      body: loading
          ? Center(
              child: SizedBox(
                  height: 60,
                  width: 60,
                  child: Image.asset('assets/images/loading.gif')))
          : error != null
              ? Center(child: Text(error!))
              : Column(children: [
                  Expanded(
                      child: ListView.builder(
                    itemCount: students.length,
                    itemBuilder: (c, i) {
                      final s = students[i];
                      return Card(
                        margin: const EdgeInsets.symmetric(
                            horizontal: 12, vertical: 4),
                        child: ListTile(
                          title: Text(s['student_name'] ??
                              'Student ${s['student_id']}'),
                          subtitle: Text(
                              'Roll ${s['roll_no'] ?? ''} • Seat C${s['seat']['col_no']} B${s['seat']['bench_no']}${s['seat']['position']}'),
                          trailing: Switch(
                            value: s['status'] == 'present',
                            onChanged: (_) => _toggleStatus(i),
                          ),
                          onTap: () => _toggleStatus(i),
                        ),
                      );
                    },
                  )),
                  Padding(
                    padding: const EdgeInsets.all(12),
                    child: SizedBox(
                      width: double.infinity,
                      child: ElevatedButton.icon(
                        icon: const Icon(Icons.save),
                        label: saving
                            ? SizedBox(
                                height: 26,
                                width: 26,
                                child: Image.asset('assets/images/loading.gif'))
                            : const Text('Save Attendance'),
                        onPressed: saving ? null : _submit,
                      ),
                    ),
                  )
                ]),
    );
  }
}

class ApiService {
  static const String _baseUrl =
      'https://jss.batighorbd.com/api'; // Use 10.0.2.2 for Android emulator

  static Future<String> _getToken() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString('token') ?? '';
  }

  static Future<dynamic> _get(String endpoint) async {
    final token = await _getToken();
    final response = await http.get(
      Uri.parse('$_baseUrl/$endpoint'),
      headers: {'Authorization': 'Bearer $token'},
    );

    if (response.statusCode == 200) {
      final data = jsonDecode(response.body);
      if (data['success']) {
        return data['data'];
      } else {
        throw Exception('API Error: ${data['error']}');
      }
    } else {
      throw Exception(
          'Failed to load data from $endpoint. Status code: ${response.statusCode}');
    }
  }

  static Future<dynamic> _post(
      String endpoint, Map<String, dynamic> body) async {
    final token = await _getToken();
    final response = await http.post(
      Uri.parse('$_baseUrl/$endpoint'),
      headers: {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer $token',
      },
      body: jsonEncode(body),
    );

    if (response.statusCode == 200) {
      final data = jsonDecode(response.body);
      if (data['success']) {
        return data; // Return full response data for success cases
      } else {
        throw Exception('API Error: ${data['error']}');
      }
    } else {
      throw Exception(
          'Failed to post data to $endpoint. Status code: ${response.statusCode}');
    }
  }

  static Future<List<dynamic>> getDuties() async {
    final data = await _get('teacher/duties.php?days=7');
    return data['duties'] as List<dynamic>;
  }

  static Future<List<dynamic>> getAttendance(
      String date, int planId, int roomId) async {
    final data = await _get(
        'exam/attendance_get.php?date=$date&plan_id=$planId&room_id=$roomId');
    return data['students'] as List<dynamic>;
  }

  static Future<void> submitAttendance(String date, int planId, int roomId,
      List<Map<String, dynamic>> entries) async {
    await _post('exam/attendance_submit.php', {
      'date': date,
      'plan_id': planId,
      'room_id': roomId,
      'entries': entries,
    });
  }

  static Future<List<dynamic>> getExams() async {
    final data = await _get('marks/get_exams.php');
    return data['exams'];
  }

  static Future<List<dynamic>> getClasses() async {
    final data = await _get('marks/get_classes.php');
    return data['classes'];
  }

  static Future<List<dynamic>> getSections() async {
    final data = await _get('marks/get_sections.php');
    return data['sections'];
  }

  static Future<List<dynamic>> getSubjectsForTeacher(
      String examId, String classId) async {
    final data =
        await _get('marks/subjects.php?exam_id=$examId&class_id=$classId');
    return data['subjects'];
  }

  static Future<List<dynamic>> getStudentsForMarking(
      String examId, String classId, String sectionId, String subjectId) async {
    final data = await _get(
        'marks/get_students_for_marking.php?exam_id=$examId&class_id=$classId&section_id=$sectionId&subject_id=$subjectId');
    return data['students'];
  }

  static Future<void> submitMarks(String examId, String classId,
      String subjectId, List<Map<String, dynamic>> marks) async {
    await _post('marks/submit.php', {
      'exam_id': examId,
      'class_id': classId,
      'subject_id': subjectId,
      'marks': marks,
    });
  }
}
