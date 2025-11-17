import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../main.dart';

class StudentMgmtHomePage extends StatefulWidget {
  const StudentMgmtHomePage({super.key});
  @override
  State<StudentMgmtHomePage> createState() => _StudentMgmtHomePageState();
}

class _StudentMgmtHomePageState extends State<StudentMgmtHomePage> {
  Map<String, dynamic>? _counts;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final c = await ApiService.studentCounts();
      if (mounted)
        setState(() {
          _counts = c;
        });
    } catch (e) {
      if (mounted)
        setState(() {
          _error = e.toString();
        });
    } finally {
      if (mounted)
        setState(() {
          _loading = false;
        });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Student Management'),
        actions: [
          PopupMenuButton<String>(
            onSelected: (v) async {
              final prefs = await SharedPreferences.getInstance();
              if (v == 'use_remote') {
                await prefs.remove('api_base_url');
                if (mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('API base set to remote')),
                  );
                }
                _load();
              } else if (v == 'use_local') {
                await prefs.setString(
                    'api_base_url', 'http://10.0.2.2/jsssms/api');
                if (mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(
                        content: Text('API base set to local (10.0.2.2)')),
                  );
                }
                _load();
              }
            },
            itemBuilder: (ctx) => const [
              PopupMenuItem(value: 'use_remote', child: Text('Use Remote API')),
              PopupMenuItem(
                  value: 'use_local', child: Text('Use Local API (10.0.2.2)')),
            ],
          )
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Padding(
                  padding: const EdgeInsets.all(16.0),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('Error: $_error',
                          style: const TextStyle(color: Colors.red)),
                      const SizedBox(height: 12),
                      ElevatedButton.icon(
                        onPressed: () async {
                          final prefs = await SharedPreferences.getInstance();
                          await prefs.setString(
                              'api_base_url', 'http://10.0.2.2/jsssms/api');
                          if (mounted) {
                            ScaffoldMessenger.of(context).showSnackBar(
                              const SnackBar(
                                  content:
                                      Text('Switched to local API (10.0.2.2)')),
                            );
                          }
                          _load();
                        },
                        icon: const Icon(Icons.wifi_tethering),
                        label: const Text('Try Local API (10.0.2.2)'),
                      ),
                      const SizedBox(height: 8),
                      OutlinedButton(
                        onPressed: _load,
                        child: const Text('Retry'),
                      ),
                    ],
                  ),
                )
              : Padding(
                  padding: const EdgeInsets.all(12.0),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Card(
                        child: ListTile(
                          title: const Text('Total Students'),
                          trailing: Text('${_counts?['total'] ?? 0}',
                              style: const TextStyle(
                                  fontSize: 18, fontWeight: FontWeight.w600)),
                        ),
                      ),
                      const SizedBox(height: 8),
                      const Text('Counts by Class',
                          style: TextStyle(fontWeight: FontWeight.w600)),
                      const SizedBox(height: 6),
                      Expanded(
                        child: ListView(
                          children: List<Widget>.from(
                            ((_counts?['by_class'] ?? []) as List<dynamic>).map(
                              (c) => ListTile(
                                leading: const Icon(Icons.school,
                                    color: Colors.indigo),
                                title: Text(c['class_name']?.toString() ?? ''),
                                trailing: Text(c['count'].toString()),
                              ),
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
      floatingActionButton: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          FloatingActionButton.extended(
            heroTag: 'list',
            onPressed: () {
              Navigator.of(context).push(
                MaterialPageRoute(builder: (_) => const StudentListPage()),
              );
            },
            icon: const Icon(Icons.people),
            label: const Text('View Students'),
          ),
          const SizedBox(height: 8),
          FloatingActionButton.extended(
            heroTag: 'add',
            backgroundColor: Colors.green,
            onPressed: () {
              Navigator.of(context).push(
                MaterialPageRoute(builder: (_) => const StudentCreatePage()),
              );
            },
            icon: const Icon(Icons.person_add),
            label: const Text('Add Student'),
          ),
        ],
      ),
    );
  }
}

class StudentListPage extends StatefulWidget {
  const StudentListPage({super.key});
  @override
  State<StudentListPage> createState() => _StudentListPageState();
}

class _StudentListPageState extends State<StudentListPage> {
  int _page = 1;
  final int _perPage = 20;
  List<dynamic> _items = [];
  int _total = 0;
  bool _loading = true;
  String? _error;
  final _qCtrl = TextEditingController();
  // Filters
  List<dynamic> _classes = [];
  List<dynamic> _sections = [];
  List<String> _groups = [];
  int? _selectedClassId;
  int? _selectedSectionId;
  String? _selectedGroup;

  @override
  void initState() {
    super.initState();
    _bootstrap();
  }

  Future<void> _bootstrap() async {
    setState(() => _loading = true);
    try {
      _classes = await ApiService.getClasses();
    } catch (e) {
      _error = e.toString();
    } finally {
      await _load();
    }
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data = await ApiService.listStudents(
        page: _page,
        perPage: _perPage,
        classId: _selectedClassId,
        sectionId: _selectedSectionId,
        group: _selectedGroup,
        q: _qCtrl.text.trim(),
      );
      if (mounted)
        setState(() {
          _total = data['total'] ?? 0;
          _items = (data['items'] ?? []) as List<dynamic>;
        });
    } catch (e) {
      if (mounted)
        setState(() {
          _error = e.toString();
        });
    } finally {
      if (mounted)
        setState(() {
          _loading = false;
        });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Students')),
      body: Column(children: [
        // Filters Row
        Padding(
          padding: const EdgeInsets.fromLTRB(8, 8, 8, 0),
          child: Row(children: [
            // Class
            Expanded(
              child: DropdownButtonFormField<int>(
                isExpanded: true,
                decoration: const InputDecoration(labelText: 'Class'),
                value: _selectedClassId,
                items: _classes.map<DropdownMenuItem<int>>((c) {
                  final id = int.tryParse(c['id'].toString());
                  final name = c['class_name']?.toString() ??
                      c['name']?.toString() ??
                      'Class';
                  return DropdownMenuItem<int>(value: id, child: Text(name));
                }).toList(),
                onChanged: (v) async {
                  setState(() => _selectedClassId = v);
                  _selectedSectionId = null;
                  _sections = [];
                  _groups = [];
                  _selectedGroup = null;
                  if (v != null) {
                    try {
                      _sections = await ApiService.getSectionsByClass(v);
                    } catch (_) {}
                    try {
                      _groups = await ApiService.getGroupsByClass(v);
                    } catch (_) {}
                  }
                  setState(() {});
                  _page = 1;
                  await _load();
                },
              ),
            ),
            const SizedBox(width: 8),
            // Section
            Expanded(
              child: DropdownButtonFormField<int>(
                isExpanded: true,
                decoration: const InputDecoration(labelText: 'Section'),
                value: _selectedSectionId,
                items: _sections.map<DropdownMenuItem<int>>((s) {
                  final id = int.tryParse(s['id'].toString());
                  final name = s['section_name']?.toString() ?? 'Section';
                  return DropdownMenuItem<int>(value: id, child: Text(name));
                }).toList(),
                onChanged: (v) async {
                  setState(() => _selectedSectionId = v);
                  _page = 1;
                  await _load();
                },
              ),
            ),
            const SizedBox(width: 8),
            // Group
            Expanded(
              child: DropdownButtonFormField<String?>(
                isExpanded: true,
                decoration: const InputDecoration(labelText: 'Group'),
                value: _selectedGroup,
                items: <DropdownMenuItem<String?>>[
                  DropdownMenuItem<String?>(
                      value: null, child: const Text('All')),
                  ..._groups
                      .map((g) =>
                          DropdownMenuItem<String?>(value: g, child: Text(g)))
                      .toList(),
                ],
                onChanged: (v) async {
                  setState(() => _selectedGroup = v);
                  _page = 1;
                  await _load();
                },
              ),
            ),
          ]),
        ),
        if (_error != null)
          Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Error: $_error',
                    style: const TextStyle(color: Colors.red)),
                const SizedBox(height: 8),
                Wrap(spacing: 8, children: [
                  ElevatedButton(
                    onPressed: _load,
                    child: const Text('Retry'),
                  ),
                  OutlinedButton(
                    onPressed: () async {
                      final prefs = await SharedPreferences.getInstance();
                      await prefs.setString(
                          'api_base_url', 'http://10.0.2.2/jsssms/api');
                      if (mounted) {
                        ScaffoldMessenger.of(context).showSnackBar(
                          const SnackBar(
                              content:
                                  Text('Switched to local API (10.0.2.2)')),
                        );
                      }
                      _load();
                    },
                    child: const Text('Use Local API'),
                  ),
                ]),
              ],
            ),
          ),
        Padding(
          padding: const EdgeInsets.all(8.0),
          child: Row(children: [
            Expanded(
                child: TextField(
              controller: _qCtrl,
              decoration:
                  const InputDecoration(labelText: 'Search by ID/Name/Father'),
            )),
            const SizedBox(width: 8),
            ElevatedButton(onPressed: _load, child: const Text('Search')),
          ]),
        ),
        _loading ? const LinearProgressIndicator() : const SizedBox.shrink(),
        _error != null
            ? Padding(
                padding: const EdgeInsets.all(12),
                child: Text('Error: $_error'))
            : const SizedBox.shrink(),
        Expanded(
          child: ListView.separated(
            itemCount: _items.length,
            separatorBuilder: (_, __) => const Divider(height: 1),
            itemBuilder: (context, i) {
              final s = _items[i] as Map<String, dynamic>;
              final photoUrl = s['photo_url']?.toString();
              return ListTile(
                leading: CircleAvatar(
                  backgroundColor: Colors.indigo.shade100,
                  backgroundImage: (photoUrl != null && photoUrl.isNotEmpty)
                      ? NetworkImage(photoUrl)
                      : null,
                  child: (photoUrl == null || photoUrl.isEmpty)
                      ? const Icon(Icons.person)
                      : null,
                ),
                title: Text('${s['student_name'] ?? ''}'),
                subtitle: Text(
                    'ID: ${s['student_id'] ?? ''} • Class: ${s['class_name'] ?? ''}'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () async {
                  final choice = await showModalBottomSheet<String>(
                    context: context,
                    builder: (ctx) => SafeArea(
                      child: Wrap(children: [
                        ListTile(
                            leading: const Icon(Icons.edit),
                            title: const Text('Edit'),
                            onTap: () => Navigator.pop(ctx, 'edit')),
                        ListTile(
                            leading: const Icon(Icons.person),
                            title: const Text('View Profile'),
                            onTap: () => Navigator.pop(ctx, 'view')),
                      ]),
                    ),
                  );
                  if (choice == 'edit') {
                    if (!mounted) return;
                    Navigator.of(context).push(MaterialPageRoute(
                        builder: (_) => StudentDetailPage(id: s['id'] as int)));
                  } else if (choice == 'view') {
                    final root = await ApiService.getSiteRoot();
                    final url = Uri.parse(
                        '$root/students/student_profile.php?id=${s['id']}');
                    await launchUrl(url, mode: LaunchMode.externalApplication);
                  }
                },
              );
            },
          ),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: Row(
            children: [
              Text('Total: $_total'),
              const Spacer(),
              IconButton(
                  onPressed: _page > 1
                      ? () {
                          setState(() => _page--);
                          _load();
                        }
                      : null,
                  icon: const Icon(Icons.chevron_left)),
              Text('Page $_page'),
              IconButton(
                  onPressed: (_page * _perPage < _total)
                      ? () {
                          setState(() => _page++);
                          _load();
                        }
                      : null,
                  icon: const Icon(Icons.chevron_right)),
            ],
          ),
        )
      ]),
    );
  }
}

class StudentDetailPage extends StatefulWidget {
  final int id;
  const StudentDetailPage({super.key, required this.id});
  @override
  State<StudentDetailPage> createState() => _StudentDetailPageState();
}

class _StudentDetailPageState extends State<StudentDetailPage> {
  bool _loading = true;
  String? _error;
  bool _saving = false;
  final _nameCtrl = TextEditingController();
  final _sidCtrl = TextEditingController();
  final _rollCtrl = TextEditingController();
  List<dynamic> _classes = [];
  List<dynamic> _sections = [];
  List<String> _groups = [];
  int? _classId;
  int? _sectionId;
  String? _group;
  final _mobileCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final s = await ApiService.getStudent(id: widget.id);
      _nameCtrl.text = s['student_name']?.toString() ?? '';
      _sidCtrl.text = s['student_id']?.toString() ?? '';
      _rollCtrl.text = s['roll_no']?.toString() ?? '';
      _classId = int.tryParse(s['class_id']?.toString() ?? '');
      _sectionId = int.tryParse(s['section_id']?.toString() ?? '');
      _mobileCtrl.text = s['mobile_no']?.toString() ?? '';
      _group = s['student_group']?.toString();
      try {
        _classes = await ApiService.getClasses();
      } catch (_) {}
      if (_classId != null) {
        try {
          _sections = await ApiService.getSectionsByClass(_classId!);
        } catch (_) {}
        try {
          _groups = await ApiService.getGroupsByClass(_classId!);
        } catch (_) {}
      }
      if (mounted) setState(() {});
    } catch (e) {
      if (mounted)
        setState(() {
          _error = e.toString();
        });
    } finally {
      if (mounted)
        setState(() {
          _loading = false;
        });
    }
  }

  Future<void> _save() async {
    setState(() => _saving = true);
    try {
      final ok = await ApiService.updateStudent(widget.id, {
        'student_name': _nameCtrl.text.trim(),
        'student_id': _sidCtrl.text.trim(),
        'roll_no': _rollCtrl.text.trim(),
        'class_id': _classId?.toString() ?? '',
        'section_id': _sectionId?.toString() ?? '',
        'mobile_no': _mobileCtrl.text.trim(),
        'student_group': _group?.toString() ?? '',
      });
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(ok ? 'Saved' : 'No changes')),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Save failed: $e')),
        );
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Student Detail')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Text('Error: $_error'))
              : Padding(
                  padding: const EdgeInsets.all(12.0),
                  child: ListView(children: [
                    TextFormField(
                        controller: _nameCtrl,
                        decoration: const InputDecoration(labelText: 'Name')),
                    const SizedBox(height: 8),
                    TextFormField(
                        controller: _sidCtrl,
                        decoration:
                            const InputDecoration(labelText: 'Student ID')),
                    const SizedBox(height: 8),
                    TextFormField(
                        controller: _rollCtrl,
                        decoration: const InputDecoration(labelText: 'Roll')),
                    const SizedBox(height: 8),
                    DropdownButtonFormField<int>(
                      isExpanded: true,
                      decoration: const InputDecoration(labelText: 'Class'),
                      value: _classId,
                      items: _classes.map<DropdownMenuItem<int>>((c) {
                        final id = int.tryParse(c['id'].toString());
                        final name = c['class_name']?.toString() ??
                            c['name']?.toString() ??
                            'Class';
                        return DropdownMenuItem<int>(
                            value: id, child: Text(name));
                      }).toList(),
                      onChanged: (v) async {
                        setState(() => _classId = v);
                        _sectionId = null;
                        _sections = [];
                        _groups = [];
                        _group = null;
                        if (v != null) {
                          try {
                            _sections = await ApiService.getSectionsByClass(v);
                          } catch (_) {}
                          try {
                            _groups = await ApiService.getGroupsByClass(v);
                          } catch (_) {}
                        }
                        setState(() {});
                      },
                    ),
                    const SizedBox(height: 8),
                    DropdownButtonFormField<int>(
                      isExpanded: true,
                      decoration: const InputDecoration(labelText: 'Section'),
                      value: _sectionId,
                      items: _sections.map<DropdownMenuItem<int>>((s) {
                        final id = int.tryParse(s['id'].toString());
                        final name = s['section_name']?.toString() ?? 'Section';
                        return DropdownMenuItem<int>(
                            value: id, child: Text(name));
                      }).toList(),
                      onChanged: (v) {
                        setState(() => _sectionId = v);
                      },
                    ),
                    const SizedBox(height: 8),
                    TextFormField(
                        controller: _mobileCtrl,
                        decoration: const InputDecoration(labelText: 'Mobile')),
                    const SizedBox(height: 8),
                    DropdownButtonFormField<String?>(
                      isExpanded: true,
                      decoration: const InputDecoration(labelText: 'Group'),
                      value: _group,
                      items: [
                        DropdownMenuItem<String?>(
                            value: null, child: const Text('None')),
                        ..._groups
                            .map((g) => DropdownMenuItem<String?>(
                                value: g, child: Text(g)))
                            .toList(),
                      ],
                      onChanged: (v) {
                        setState(() => _group = v);
                      },
                    ),
                    const SizedBox(height: 16),
                    ElevatedButton(
                        onPressed: _saving ? null : _save,
                        child: Text(_saving ? 'Saving...' : 'Save')),
                  ]),
                ),
    );
  }
}

class StudentCreatePage extends StatefulWidget {
  const StudentCreatePage({super.key});
  @override
  State<StudentCreatePage> createState() => _StudentCreatePageState();
}

class _StudentCreatePageState extends State<StudentCreatePage> {
  final _formKey = GlobalKey<FormState>();
  final _nameCtrl = TextEditingController();
  final _sidCtrl = TextEditingController();
  final _rollCtrl = TextEditingController();
  bool _saving = false;
  // Dropdown data/state
  List<dynamic> _classes = [];
  List<dynamic> _sections = [];
  List<String> _groups = [];
  int? _classId;
  int? _sectionId;
  String? _group;

  @override
  void initState() {
    super.initState();
    _bootstrap();
  }

  Future<void> _bootstrap() async {
    try {
      _classes = await ApiService.getClasses();
    } catch (_) {}
    if (mounted) setState(() {});
  }

  Future<void> _create() async {
    if (!_formKey.currentState!.validate()) return;
    if (_classId == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please select a class')),
      );
      return;
    }
    setState(() => _saving = true);
    try {
      final id = await ApiService.createStudent({
        'student_name': _nameCtrl.text.trim(),
        'student_id': _sidCtrl.text.trim(),
        'class_id': _classId?.toString() ?? '',
        'section_id': _sectionId?.toString() ?? '',
        'roll_no': _rollCtrl.text.trim(),
        'student_group': _group?.toString() ?? '',
      });
      if (!mounted) return;
      if (id > 0) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Student created')),
        );
        Navigator.of(context).pop();
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Creation failed')),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Creation failed: $e')),
        );
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Add Student')),
      body: Padding(
        padding: const EdgeInsets.all(12.0),
        child: Form(
          key: _formKey,
          child: ListView(children: [
            TextFormField(
                controller: _nameCtrl,
                decoration: const InputDecoration(labelText: 'Name'),
                validator: (v) => (v == null || v.isEmpty) ? 'Required' : null),
            const SizedBox(height: 8),
            TextFormField(
                controller: _sidCtrl,
                decoration: const InputDecoration(labelText: 'Student ID'),
                validator: (v) => (v == null || v.isEmpty) ? 'Required' : null),
            const SizedBox(height: 8),
            DropdownButtonFormField<int>(
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'Class'),
              value: _classId,
              items: _classes.map<DropdownMenuItem<int>>((c) {
                final id = int.tryParse(c['id'].toString());
                final name = c['class_name']?.toString() ??
                    c['name']?.toString() ??
                    'Class';
                return DropdownMenuItem<int>(value: id, child: Text(name));
              }).toList(),
              onChanged: (v) async {
                setState(() => _classId = v);
                _sectionId = null;
                _sections = [];
                _groups = [];
                _group = null;
                if (v != null) {
                  try {
                    _sections = await ApiService.getSectionsByClass(v);
                  } catch (_) {}
                  try {
                    _groups = await ApiService.getGroupsByClass(v);
                  } catch (_) {}
                }
                setState(() {});
              },
              validator: (v) => (v == null) ? 'Required' : null,
            ),
            const SizedBox(height: 8),
            DropdownButtonFormField<int>(
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'Section'),
              value: _sectionId,
              items: _sections.map<DropdownMenuItem<int>>((s) {
                final id = int.tryParse(s['id'].toString());
                final name = s['section_name']?.toString() ?? 'Section';
                return DropdownMenuItem<int>(value: id, child: Text(name));
              }).toList(),
              onChanged: (v) {
                setState(() => _sectionId = v);
              },
            ),
            const SizedBox(height: 8),
            TextFormField(
                controller: _rollCtrl,
                decoration: const InputDecoration(labelText: 'Roll')),
            const SizedBox(height: 8),
            DropdownButtonFormField<String?>(
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'Group'),
              value: _group,
              items: [
                DropdownMenuItem<String?>(
                    value: null, child: const Text('None')),
                ..._groups
                    .map((g) =>
                        DropdownMenuItem<String?>(value: g, child: Text(g)))
                    .toList(),
              ],
              onChanged: (v) {
                setState(() => _group = v);
              },
            ),
            const SizedBox(height: 16),
            ElevatedButton(
                onPressed: _saving ? null : _create,
                child: Text(_saving ? 'Saving...' : 'Create')),
          ]),
        ),
      ),
    );
  }
}
